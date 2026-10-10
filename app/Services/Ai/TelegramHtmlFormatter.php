<?php

namespace App\Services\Ai;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

class TelegramHtmlFormatter
{
    private const MAX_MESSAGE_CHARACTERS = 3500;

    private MarkdownConverter $markdown;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $this->markdown = new MarkdownConverter($environment);
    }

    /**
     * @return array<int, array{html:string,plain:string}>
     */
    public function chunks(string $text): array
    {
        $source = $this->isTelegramHtml($text)
            ? $text
            : (string) $this->markdown->convert($text);
        $root = $this->parseFragment($source);
        $tokens = [];
        $this->renderChildren($root, $tokens);
        $this->trimTrailingBreaks($tokens);

        return $this->splitTokens($tokens);
    }

    private function isTelegramHtml(string $text): bool
    {
        return preg_match('/^\s*<(?:b|strong|i|em|u|ins|s|strike|del|code|pre|a|blockquote|br)\b/i', $text) === 1;
    }

    private function parseFragment(string $html): DOMNode
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="telegram-root">'.$html.'</div></body></html>',
                LIBXML_NONET | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        if (! $loaded) {
            throw new \RuntimeException('Telegram message HTML could not be parsed.');
        }

        $roots = $document->getElementsByTagName('div');
        foreach ($roots as $root) {
            if ($root instanceof DOMElement && $root->getAttribute('id') === 'telegram-root') {
                return $root;
            }
        }

        throw new \RuntimeException('Telegram message HTML root was not found.');
    }

    /**
     * @param  array<int, array<string, string>>  $tokens
     */
    private function renderChildren(DOMNode $parent, array &$tokens): void
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = $child->nodeValue ?? '';
                if (trim($text) === ''
                    && $parent instanceof DOMElement
                    && (in_array($parent->tagName, ['ul', 'ol'], true)
                        || ($parent->tagName === 'div' && str_contains($text, "\n")))) {
                    continue;
                }
                $this->appendText($tokens, $text);

                continue;
            }

            if ($child instanceof DOMElement) {
                $this->renderElement($child, $tokens);
            }
        }
    }

    /**
     * @param  array<int, array<string, string>>  $tokens
     */
    private function renderElement(DOMElement $element, array &$tokens): void
    {
        $tag = strtolower($element->tagName);

        if ($tag === 'p') {
            $this->renderChildren($element, $tokens);
            $inListItem = $element->parentNode instanceof DOMElement
                && strtolower($element->parentNode->tagName) === 'li';
            $this->appendText($tokens, $inListItem ? "\n" : "\n\n");

            return;
        }

        if (preg_match('/^h[1-6]$/', $tag) === 1) {
            $this->openTag($tokens, 'b');
            $this->renderChildren($element, $tokens);
            $this->closeTag($tokens, 'b');
            $this->appendText($tokens, "\n\n");

            return;
        }

        if (in_array($tag, ['strong', 'b', 'em', 'i', 'u', 'ins', 's', 'strike', 'del', 'code'], true)) {
            $telegramTag = match ($tag) {
                'strong', 'b' => 'b',
                'em', 'i' => 'i',
                's', 'strike', 'del' => 's',
                default => $tag,
            };
            $this->openTag($tokens, $telegramTag);
            $this->renderChildren($element, $tokens);
            $this->closeTag($tokens, $telegramTag);

            return;
        }

        if ($tag === 'pre') {
            $this->openTag($tokens, 'pre');
            $this->appendText($tokens, $element->textContent);
            $this->closeTag($tokens, 'pre');
            $this->appendText($tokens, "\n\n");

            return;
        }

        if ($tag === 'a') {
            $url = $this->safeUrl($element->getAttribute('href'));
            if ($url !== null) {
                $this->openTag($tokens, 'a', ' href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"');
                $this->renderChildren($element, $tokens);
                $this->closeTag($tokens, 'a');
            } else {
                $this->renderChildren($element, $tokens);
            }

            return;
        }

        if ($tag === 'br') {
            $this->appendText($tokens, "\n");

            return;
        }

        if ($tag === 'ul' || $tag === 'ol') {
            $this->renderList($element, $tokens, $tag === 'ol');

            return;
        }

        if ($tag === 'li') {
            $this->renderChildren($element, $tokens);
            $this->appendText($tokens, "\n");

            return;
        }

        if ($tag === 'blockquote') {
            $this->openTag($tokens, 'blockquote');
            $this->renderChildren($element, $tokens);
            $this->closeTag($tokens, 'blockquote');
            $this->appendText($tokens, "\n\n");

            return;
        }

        if ($tag === 'hr') {
            $this->appendText($tokens, "\n────────\n");

            return;
        }

        if ($tag === 'img') {
            $this->appendText($tokens, $element->getAttribute('alt'));

            return;
        }

        $this->renderChildren($element, $tokens);
    }

    /**
     * @param  array<int, array<string, string>>  $tokens
     */
    private function renderList(DOMElement $list, array &$tokens, bool $numbered): void
    {
        $index = max(1, (int) $list->getAttribute('start'));
        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
                continue;
            }

            $this->appendText($tokens, $numbered ? $index.'. ' : '• ');
            $this->renderChildren($item, $tokens);
            $this->appendLineBreak($tokens);
            $index++;
        }
    }

    private function safeUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts = parse_url($url);

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    /**
     * @param  array<int, array<string, string>>  $tokens
     */
    private function appendText(array &$tokens, string $text): void
    {
        if ($text !== '') {
            $tokens[] = ['type' => 'text', 'value' => $text];
        }
    }

    /**
     * @param  array<int, array<string, string>>  $tokens
     */
    private function appendLineBreak(array &$tokens): void
    {
        $last = end($tokens);
        if ($last !== false && $last['type'] === 'text' && str_ends_with($last['value'], "\n")) {
            return;
        }

        $this->appendText($tokens, "\n");
    }

    /**
     * @param  array<int, array<string, string>>  $tokens
     */
    private function trimTrailingBreaks(array &$tokens): void
    {
        while ($tokens !== [] && end($tokens)['type'] === 'text') {
            $last = array_pop($tokens);
            $text = rtrim($last['value'], "\n");
            if ($text !== '') {
                $tokens[] = ['type' => 'text', 'value' => $text];
                break;
            }
        }
    }

    /**
     * @param  array<int, array<string, string>>  $tokens
     */
    private function openTag(array &$tokens, string $tag, string $attributes = ''): void
    {
        $tokens[] = [
            'type' => 'open',
            'tag' => $tag,
            'value' => '<'.$tag.$attributes.'>',
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $tokens
     */
    private function closeTag(array &$tokens, string $tag): void
    {
        $tokens[] = ['type' => 'close', 'tag' => $tag, 'value' => '</'.$tag.'>'];
    }

    /**
     * @param  array<int, array<string, string>>  $tokens
     * @return array<int, array{html:string,plain:string}>
     */
    private function splitTokens(array $tokens): array
    {
        $chunks = [];
        $html = '';
        $plain = '';
        $length = 0;
        $openTags = [];

        $finishChunk = function () use (&$chunks, &$html, &$plain, &$length, &$openTags): void {
            foreach (array_reverse($openTags) as $tag) {
                $html .= '</'.$tag['tag'].'>';
            }
            if ($plain !== '') {
                $chunks[] = ['html' => $html, 'plain' => $plain];
            }
            $html = implode('', array_column($openTags, 'value'));
            $plain = '';
            $length = 0;
        };

        foreach ($tokens as $token) {
            if ($token['type'] === 'open') {
                $openTags[] = ['tag' => $token['tag'], 'value' => $token['value']];
                $html .= $token['value'];

                continue;
            }

            if ($token['type'] === 'close') {
                $html .= $token['value'];
                for ($index = count($openTags) - 1; $index >= 0; $index--) {
                    if ($openTags[$index]['tag'] === $token['tag']) {
                        array_splice($openTags, $index, 1);
                        break;
                    }
                }

                continue;
            }

            $characters = mb_str_split($token['value'], 1, 'UTF-8');
            $offset = 0;
            while ($offset < count($characters)) {
                if ($length >= self::MAX_MESSAGE_CHARACTERS) {
                    $finishChunk();
                }

                $available = self::MAX_MESSAGE_CHARACTERS - $length;
                $segment = '';
                $segmentLength = 0;
                while ($offset < count($characters)) {
                    $character = $characters[$offset];
                    $characterLength = mb_ord($character, 'UTF-8') > 0xFFFF ? 2 : 1;
                    if ($segmentLength + $characterLength > $available) {
                        break;
                    }

                    $segment .= $character;
                    $segmentLength += $characterLength;
                    $offset++;
                }

                if ($segment !== '') {
                    $html .= htmlspecialchars($segment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $plain .= $segment;
                    $length += $segmentLength;
                }
                if ($offset < count($characters)) {
                    $finishChunk();
                }
            }
        }

        foreach (array_reverse($openTags) as $tag) {
            $html .= '</'.$tag['tag'].'>';
        }
        if ($plain !== '') {
            $chunks[] = ['html' => $html, 'plain' => $plain];
        }

        return $chunks === [] ? [['html' => '', 'plain' => '']] : $chunks;
    }
}
