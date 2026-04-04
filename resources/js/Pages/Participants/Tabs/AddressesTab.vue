<script setup lang="ts">
// ─── AddressesTab.vue ─────────────────────────────────────────────────────────
// Participant addresses: home, mailing, emergency_shelter, nursing_facility.
// Add/edit in modal. Primary address shown with badge. Status active/inactive.
// Address validation done server-side; client shows returned errors.
// ─────────────────────────────────────────────────────────────────────────────

import { ref } from 'vue'
import axios from 'axios'
import { PlusIcon, HomeIcon } from '@heroicons/vue/24/outline'

interface Address {
    id: number
    address_type: string
    street_1: string
    street_2: string | null
    city: string
    state: string
    zip_code: string
    county: string | null
    country: string
    is_primary: boolean
    is_active: boolean
    effective_date: string | null
    notes: string | null
}

interface Participant {
    id: number
}

const props = defineProps<{
    participant: Participant
    addresses: Address[]
}>()

const ADDRESS_TYPE_LABELS: Record<string, string> = {
    home: 'Home',
    mailing: 'Mailing',
    emergency_shelter: 'Emergency Shelter',
    nursing_facility: 'Nursing Facility',
    assisted_living: 'Assisted Living',
    other: 'Other',
}

const addresses = ref<Address[]>(props.addresses)
const showModal = ref(false)
const saving = ref(false)
const error = ref('')
const editingId = ref<number | null>(null)

const blankForm = () => ({
    address_type: 'home',
    street_1: '',
    street_2: '',
    city: '',
    state: '',
    zip_code: '',
    county: '',
    country: 'US',
    is_primary: false,
    notes: '',
})
const form = ref(blankForm())

function openAdd() {
    editingId.value = null
    form.value = blankForm()
    error.value = ''
    showModal.value = true
}

function openEdit(address: Address) {
    editingId.value = address.id
    form.value = {
        address_type: address.address_type,
        street_1: address.street_1,
        street_2: address.street_2 ?? '',
        city: address.city,
        state: address.state,
        zip_code: address.zip_code,
        county: address.county ?? '',
        country: address.country,
        is_primary: address.is_primary,
        notes: address.notes ?? '',
    }
    error.value = ''
    showModal.value = true
}

async function submit() {
    if (!form.value.street_1.trim() || !form.value.city.trim()) {
        error.value = 'Street and city are required.'
        return
    }
    saving.value = true
    error.value = ''
    const payload = {
        ...form.value,
        street_2: form.value.street_2 || null,
        county: form.value.county || null,
        notes: form.value.notes || null,
    }
    try {
        if (editingId.value) {
            const res = await axios.put(
                `/participants/${props.participant.id}/addresses/${editingId.value}`,
                payload,
            )
            const idx = addresses.value.findIndex((a) => a.id === editingId.value)
            if (idx !== -1) addresses.value[idx] = res.data
        } else {
            const res = await axios.post(`/participants/${props.participant.id}/addresses`, payload)
            addresses.value.push(res.data)
        }
        showModal.value = false
        form.value = blankForm()
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } }
        error.value = e.response?.data?.message ?? 'Failed to save address.'
        saving.value = false
    }
}
</script>

<template>
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Addresses</h2>
            <button
                class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                @click="openAdd"
            >
                <PlusIcon class="w-3 h-3" />
                Add Address
            </button>
        </div>

        <div
            v-if="addresses.length === 0"
            class="py-12 text-center text-gray-400 dark:text-slate-500 text-sm"
        >
            No addresses on file.
        </div>
        <div v-else class="space-y-2">
            <div
                v-for="address in addresses"
                :key="address.id"
                :class="[
                    'flex items-start gap-3 rounded-lg px-4 py-3 border',
                    address.is_active
                        ? 'bg-white dark:bg-slate-800 border-gray-200 dark:border-slate-700'
                        : 'bg-gray-50 dark:bg-slate-800/50 border-gray-100 dark:border-slate-700 opacity-60',
                ]"
            >
                <div
                    class="shrink-0 w-8 h-8 rounded-full bg-gray-100 dark:bg-slate-700 flex items-center justify-center"
                >
                    <HomeIcon class="w-4 h-4 text-gray-400 dark:text-slate-500" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{
                            ADDRESS_TYPE_LABELS[address.address_type] ?? address.address_type
                        }}</span>
                        <span
                            v-if="address.is_primary"
                            class="text-xs bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded"
                            >Primary</span
                        >
                        <span
                            v-if="!address.is_active"
                            class="text-xs bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 px-1.5 py-0.5 rounded"
                            >Inactive</span
                        >
                    </div>
                    <div class="text-sm text-gray-700 dark:text-slate-300 mt-0.5">
                        {{ address.street_1
                        }}<span v-if="address.street_2">, {{ address.street_2 }}</span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-slate-400">
                        {{ address.city }}, {{ address.state }} {{ address.zip_code }}
                        <span v-if="address.county"> · {{ address.county }} County</span>
                    </div>
                    <p
                        v-if="address.notes"
                        class="text-xs text-gray-400 dark:text-slate-500 mt-0.5"
                    >
                        {{ address.notes }}
                    </p>
                </div>
                <button
                    class="text-xs px-2 py-1 border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-slate-400 rounded hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors shrink-0"
                    @click="openEdit(address)"
                >
                    Edit
                </button>
            </div>
        </div>

        <!-- Add/Edit modal -->
        <div
            v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        >
            <div
                class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-lg w-full p-5 max-h-[90vh] overflow-y-auto"
            >
                <h3 class="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">
                    {{ editingId ? 'Edit Address' : 'Add Address' }}
                </h3>
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Address Type</label
                        >
                        <select
                            v-model="form.address_type"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        >
                            <option
                                v-for="(label, key) in ADDRESS_TYPE_LABELS"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >Street *</label
                        >
                        <input
                            v-model="form.street_1"
                            type="text"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700 mb-2"
                        />
                        <input
                            v-model="form.street_2"
                            type="text"
                            placeholder="Apt, Suite, etc."
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >City *</label
                        >
                        <input
                            v-model="form.city"
                            type="text"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >State</label
                        >
                        <input
                            v-model="form.state"
                            type="text"
                            maxlength="2"
                            placeholder="CA"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >ZIP Code</label
                        >
                        <input
                            v-model="form.zip_code"
                            type="text"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1"
                            >County</label
                        >
                        <input
                            v-model="form.county"
                            type="text"
                            placeholder="Optional"
                            class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-2 py-1.5 bg-white dark:bg-slate-700"
                        />
                    </div>
                </div>
                <label
                    class="flex items-center gap-2 text-xs text-gray-700 dark:text-slate-300 mb-3 cursor-pointer"
                >
                    <input
                        v-model="form.is_primary"
                        type="checkbox"
                        class="rounded border-gray-300"
                    />
                    Set as primary address
                </label>
                <p v-if="error" class="text-red-600 dark:text-red-400 text-xs mb-2">{{ error }}</p>
                <div class="flex gap-2">
                    <button
                        :disabled="saving"
                        class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
                        @click="submit"
                    >
                        {{ saving ? 'Saving...' : 'Save' }}
                    </button>
                    <button
                        class="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg transition-colors"
                        @click="showModal = false; error = ''"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
