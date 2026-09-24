<div x-show="customerModalOpen" x-cloak
     x-on:keydown.escape.window="closeCustomerModal()"
     class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
     role="dialog" aria-modal="true" aria-labelledby="customer-modal-title">
    <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 id="customer-modal-title" class="text-lg font-semibold text-gray-900">{{ __('Add Customer') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('Create a customer.') }}</p>
            </div>
            <button type="button" @click="closeCustomerModal()" class="text-gray-400 hover:text-gray-700" aria-label="{{ __('Close') }}">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>

        <form class="mt-5 space-y-4" @submit.prevent="createCustomer">
            <div>
                <label for="sale_customer_name" class="block text-sm font-medium text-gray-700">{{ __('Name *') }}</label>
                <input id="sale_customer_name" x-model="customerForm.name" required type="text" maxlength="255"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label for="sale_customer_phone" class="block text-sm font-medium text-gray-700">{{ __('Phone *') }}</label>
                <input id="sale_customer_phone" x-model="customerForm.phone" required type="tel" maxlength="50"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label for="sale_customer_email" class="block text-sm font-medium text-gray-700">{{ __('Email (optional)') }}</label>
                <input id="sale_customer_email" x-model="customerForm.email" type="email" maxlength="255"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label for="sale_customer_address" class="block text-sm font-medium text-gray-700">{{ __('Address (optional)') }}</label>
                <input id="sale_customer_address" x-model="customerForm.address" type="text" maxlength="255"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>

            <p x-show="customerError" x-text="customerError" class="text-sm text-red-600" role="alert"></p>

            <div class="flex justify-end gap-3 border-t border-gray-100 pt-4">
                <button type="button" @click="closeCustomerModal()" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ __('Cancel') }}
                </button>
                <button type="submit" x-bind:disabled="customerSaving" class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                    <span x-show="!customerSaving">{{ __('Create customer') }}</span>
                    <span x-show="customerSaving" x-cloak>{{ __('Creating…') }}</span>
                </button>
            </div>
        </form>
    </div>
</div>
