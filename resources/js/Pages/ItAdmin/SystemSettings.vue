<!-- ItAdmin/SystemSettings.vue -->
<!-- Tenant system settings page. IT Admins can update the PACE contract number, state,
     and timezone for the tenant organization. Also shows the current integration connector
     status grid and links to the state Medicaid configuration page. -->

<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import {
    Cog6ToothIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

interface TenantSettings {
    pace_contract: string
    state: string
    timezone: string
    hipaa_timeout: number
}

interface MedicaidConfigRow {
    id: number
    state_code: string
    state_name: string
    submission_format: string
    is_active: boolean
}

interface IntegrationStatus {
    hl7_adt: string
    lab_result: string
}

interface Props {
    tenant: TenantSettings
    medicaidConfigs: MedicaidConfigRow[]
    canEdit: boolean
    integrationStatus: IntegrationStatus
}

const props = defineProps<Props>()

const US_TIMEZONES = [
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Phoenix',
    'America/Los_Angeles',
    'America/Anchorage',
    'Pacific/Honolulu',
]

const INTEGRATION_LABELS: Record<string, string> = {
    hl7_adt: 'HL7 ADT Connector',
    lab_result: 'Lab Results Connector',
}

const integrationIcon = (status: string) => {
    if (status === 'configured') return CheckCircleIcon
    if (status === 'error') return ExclamationTriangleIcon
    return ClockIcon
}

const integrationClass = (status: string) => {
    if (status === 'configured') return 'text-green-600 dark:text-green-400'
    if (status === 'error') return 'text-red-600 dark:text-red-400'
    return 'text-yellow-600 dark:text-yellow-400'
}

const form = useForm({
    pace_contract: props.tenant.pace_contract ?? '',
    state: props.tenant.state ?? '',
    timezone: props.tenant.timezone ?? 'America/New_York',
})

const submit = () => {
    form.put('/admin/settings')
}
</script>

<template>
    <AppShell>
        <Head title="System Settings" />

        <div class="max-w-4xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="flex items-center gap-3 mb-8">
                <Cog6ToothIcon class="w-7 h-7 text-blue-600 dark:text-blue-400" />
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">System Settings</h1>
                    <p class="text-sm text-gray-500 dark:text-slate-400">Tenant configuration and integration status</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Tenant Config Form -->
                <div class="lg:col-span-2">
                    <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-6">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-4">Organization Settings</h2>
                        <form @submit.prevent="submit" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="pace-contract">
                                    PACE Contract Number (H-number)
                                </label>
                                <input
                                    id="pace-contract"
                                    v-model="form.pace_contract"
                                    type="text"
                                    :disabled="!props.canEdit"
                                    placeholder="H0000"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm disabled:opacity-60 disabled:cursor-not-allowed"
                                />
                                <p v-if="form.errors.pace_contract" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.pace_contract }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="state">
                                    State (2-letter code)
                                </label>
                                <input
                                    id="state"
                                    v-model="form.state"
                                    type="text"
                                    maxlength="2"
                                    :disabled="!props.canEdit"
                                    placeholder="OH"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm uppercase disabled:opacity-60 disabled:cursor-not-allowed"
                                />
                                <p v-if="form.errors.state" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.state }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1" for="timezone">Timezone</label>
                                <select
                                    id="timezone"
                                    v-model="form.timezone"
                                    :disabled="!props.canEdit"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm text-gray-700 dark:text-slate-300 disabled:opacity-60 disabled:cursor-not-allowed"
                                >
                                    <option v-for="tz in US_TIMEZONES" :key="tz" :value="tz">{{ tz }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                                    HIPAA Auto-Logout (minutes)
                                </label>
                                <input
                                    :value="props.tenant.hipaa_timeout"
                                    type="number"
                                    disabled
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 text-sm opacity-60 cursor-not-allowed"
                                />
                                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Set via SESSION_LIFETIME in server .env</p>
                            </div>

                            <div v-if="props.canEdit" class="flex justify-end pt-2">
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="px-4 py-2 text-sm rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium disabled:opacity-50 transition-colors"
                                >
                                    {{ form.processing ? 'Saving...' : 'Save Settings' }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Medicaid Configs (read-only) -->
                    <div class="mt-6 bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">State Medicaid Configurations</h2>
                            <button
                                @click="router.visit('/it-admin/state-config')"
                                class="text-xs px-3 py-1 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                            >
                                Manage
                            </button>
                        </div>
                        <div v-if="props.medicaidConfigs.length === 0" class="text-sm text-gray-500 dark:text-slate-400">
                            No state configurations. <button @click="router.visit('/it-admin/state-config')" class="text-blue-600 dark:text-blue-400 hover:underline">Add one</button>.
                        </div>
                        <table v-else class="w-full text-sm">
                            <thead>
                                <tr>
                                    <th class="text-left py-2 font-medium text-gray-600 dark:text-slate-400">State</th>
                                    <th class="text-left py-2 font-medium text-gray-600 dark:text-slate-400">Format</th>
                                    <th class="text-left py-2 font-medium text-gray-600 dark:text-slate-400">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                                <tr v-for="c in props.medicaidConfigs" :key="c.id">
                                    <td class="py-2 text-gray-900 dark:text-slate-100">{{ c.state_code }} - {{ c.state_name }}</td>
                                    <td class="py-2 text-gray-600 dark:text-slate-400">{{ c.submission_format }}</td>
                                    <td class="py-2">
                                        <span :class="c.is_active ? 'text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-slate-500'"
                                            class="text-xs">{{ c.is_active ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Integration Status -->
                <div>
                    <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-6">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-4">Integration Status</h2>
                        <div class="space-y-3">
                            <div v-for="(status, key) in props.integrationStatus" :key="key"
                                class="flex items-center justify-between p-3 rounded-lg bg-gray-50 dark:bg-slate-700/50">
                                <span class="text-sm text-gray-700 dark:text-slate-300">{{ INTEGRATION_LABELS[key] ?? key }}</span>
                                <div class="flex items-center gap-1">
                                    <component :is="integrationIcon(status)" class="w-4 h-4" :class="integrationClass(status)" />
                                    <span class="text-xs capitalize" :class="integrationClass(status)">{{ status }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button @click="router.visit('/it-admin/integrations')"
                                class="w-full text-center text-sm text-blue-600 dark:text-blue-400 hover:underline py-2">
                                View Integration Log
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
