<?php

namespace App\Services;

use App\Models\Customer;

/**
 * Builds a vCard 3.0 (.vcf) contact file.
 *
 * vCard is the format Android, iOS, Google Contacts and Outlook all import, and it is
 * plain text: the whole export is produced here and can be reasoned about without a
 * browser or a device.
 *
 * Two rules shape everything below:
 *
 *  1. Only properties the customer actually has are emitted. Importers vary in how they
 *     treat an empty TEL or an ADR with no components, and several will create a blank row
 *     or drop the contact entirely. A property that would be empty is simply not written,
 *     so no importer can ever see `null`, `undefined` or an empty value.
 *  2. A customer is exported only if they are actually a CONTACT — a name, or at least a
 *     phone number or an email. A row with none of those has nothing to import and would
 *     become a blank entry in somebody's address book.
 */
class VCardExporter
{
    /** vCard 3.0 is the widest-supported version; parts of iOS still ignore 4.0. */
    private const VERSION = '3.0';

    /**
     * Render a collection of customers as ONE .vcf file.
     *
     * Multiple BEGIN/END pairs in a single file is exactly what every contact importer
     * expects, so one download imports every customer at once.
     *
     * @param  iterable<Customer>  $customers
     */
    public function render(iterable $customers): string
    {
        $lines = [];

        foreach ($customers as $customer) {
            $lines = array_merge($lines, $this->card($customer) ?? []);
        }

        // CRLF is required by RFC 6350 and is what Android and iOS parse most reliably;
        // a Unix-newline .vcf imports inconsistently, particularly on iOS.
        return $lines === [] ? '' : implode("\r\n", $lines)."\r\n";
    }

    /** Whether a customer carries enough data to be a real contact entry. */
    public function isExportable(Customer $customer): bool
    {
        return filled($customer->name)
            || filled($customer->phone)
            || filled($customer->email);
    }

    /** One BEGIN/END block, or null when the customer is not a usable contact. */
    private function card(Customer $customer): ?array
    {
        if (! $this->isExportable($customer)) {
            return null;
        }

        $name = trim((string) $customer->name);
        $label = $name !== '' ? $name : $this->fallbackLabel($customer);

        $lines = [
            'BEGIN:VCARD',
            'VERSION:'.self::VERSION,
            // N is the structured name, FN the display name. Both are written because
            // importers key off either: Android reads N, several desktop apps read FN.
            'N:'.$this->structuredName($name !== '' ? $name : $label),
            'FN:'.$this->escapeValue($label),
        ];

        if (filled($customer->phone)) {
            // No vCard escaping here: a phone number is digits and punctuation, and a
            // stray semicolon in it is far more likely to be a typo than a real value.
            $lines[] = 'TEL;TYPE=CELL:'.trim($customer->phone);
        }

        if (filled($customer->email)) {
            $lines[] = 'EMAIL;TYPE=INTERNET:'.$this->escapeValue($customer->email);
        }

        if (filled($customer->address)) {
            $lines[] = $this->adr($customer->address);
        }

        if (filled($customer->notes)) {
            $lines[] = 'NOTE:'.$this->escapeValue($customer->notes);
        }

        $lines[] = 'END:VCARD';

        return $lines;
    }

    /** ADR from a single free-text address: street,;;city;region;postal;country. */
    private function adr(string $address): string
    {
        // The customers table stores one address string with no street/city/postcode
        // split, so the whole value becomes the street component — the part every
        // importer actually displays. Inventing a city or a postcode would be fabrication.
        return 'ADR;TYPE=HOME:;;'.$this->escapeValue(trim($address)).';;;;';
    }

    /**
     * vCard 3.0 structured name: family;given;additional;prefix;suffix.
     *
     * "Budi Santoso" → Santoso;Budi;;;
     *
     * The family name is assumed to be the LAST word, which is right far more often
     * than not in Indonesian and Western names. It is only a guess about ORDER, never
     * about content: no component is dropped, and importers display FN rather than N,
     * so a mis-split N cannot lose the name.
     *
     * Parts are escaped individually so a comma inside a name cannot inject an extra
     * component.
     */
    private function structuredName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if ($parts === []) {
            return ';;;;';
        }

        if (count($parts) === 1) {
            return $this->escapeValue($parts[0]).';;;;';
        }

        $family = array_pop($parts);

        return $this->escapeValue($family).';'.$this->escapeValue(implode(' ', $parts)).';;;';
    }

    /** Something a person can recognise, for a contact with no name recorded. */
    private function fallbackLabel(Customer $customer): string
    {
        return (string) (filled($customer->phone) ? $customer->phone : ($customer->email ?? ''));
    }

    /**
     * vCard 3.0 text escaping.
     *
     * Backslash, semicolon, comma and newline must all be escaped, and a real line break
     * becomes the literal two characters \n — leaving a raw newline in would split the
     * property across lines and corrupt the whole file.
     */
    private function escapeValue(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value
        );
    }
}