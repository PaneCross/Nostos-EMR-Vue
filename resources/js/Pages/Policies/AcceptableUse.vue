<script setup lang="ts">
// ─── Policies / Staff Acceptable-Use Policy ───────────────────────────────────
// Staff-facing acceptable-use expectations tied to HIPAA Security Rule
// workforce-access standards (45 CFR §164.308(a)(3), (a)(4), (a)(5)).
// Phase 5 (MVP roadmap).
// ─────────────────────────────────────────────────────────────────────────────

import { Head, Link } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { UserGroupIcon } from '@heroicons/vue/24/outline'
</script>

<template>
    <AppShell>
        <Head title="Acceptable-Use Policy" />

        <div class="px-6 py-8 max-w-3xl mx-auto">
            <div class="flex items-start gap-3 mb-6">
                <UserGroupIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400 mt-0.5" />
                <div>
                    <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Staff Acceptable-Use Policy</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">HIPAA Security Rule · 45 CFR §164.308(a)(3)-(5)</p>
                </div>
            </div>

            <article class="prose prose-slate dark:prose-invert max-w-none text-sm leading-relaxed">
                <h2>1. Scope</h2>
                <p>
                    This policy applies to every individual granted access to NostosEMR: employees, contractors,
                    volunteers, and temporary staff. By using this system you acknowledge and agree to these terms.
                </p>

                <h2>2. Minimum Necessary</h2>
                <p>
                    Access participant records only as required to perform your assigned duties
                    (45 CFR §164.502(b)). Department role-based access controls enforce this automatically; you
                    must not circumvent them. When you must access a record outside your normal department scope
                    (e.g., cross-coverage), use the <strong>Break-the-Glass</strong> workflow and document the
                    justification.
                </p>

                <h2>3. Authentication &amp; Session</h2>
                <ul>
                    <li>Do not share your login credentials with anyone, ever.</li>
                    <li>Lock your screen whenever you step away. The system will auto-lock after 120 minutes of inactivity.</li>
                    <li>Complete all HIPAA training and attestations on schedule (annual minimum).</li>
                </ul>

                <h2>4. Prohibited Conduct</h2>
                <ul>
                    <li>Accessing records of participants, staff, family members, or public figures out of curiosity.</li>
                    <li>Copying, photographing, or transmitting PHI outside the sanctioned workflows (EHI export, secure messaging, authorized integrations).</li>
                    <li>Installing unauthorized software, browser extensions, or agents that can capture screen content.</li>
                    <li>Using personal messaging, personal email, or social media to communicate about participants.</li>
                    <li>Altering, concealing, or deleting audit-relevant records.</li>
                </ul>

                <h2>5. Documentation</h2>
                <p>
                    Every clinical action you take is logged with your identity, timestamp, and before/after values
                    in an append-only audit trail retained for a minimum of 10 years per 42 CFR §460.210. Clinical
                    notes, once signed, are immutable: corrections are added as addenda.
                </p>

                <h2>6. Incident Reporting</h2>
                <p>
                    If you suspect or observe a privacy or security incident (PHI disclosure, lost device, phishing,
                    unauthorized access), report it immediately to the Compliance Officer via the grievance or
                    incident module, or directly if time-sensitive. Timely reporting is a legal obligation, not
                    optional.
                </p>

                <h2>7. Consequences</h2>
                <p>
                    Violations of this policy may result in corrective action up to and including termination,
                    civil and criminal penalties under HIPAA (45 CFR §160.404), and referral to law enforcement.
                </p>

                <hr class="my-6">

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Policy owner: IT Administrator + Compliance Officer. Reviewed annually.
                    See also:
                    <Link href="/policies/npp" class="text-indigo-600 dark:text-indigo-400 hover:underline">Notice of Privacy Practices</Link>
                    ·
                    <Link href="/policies/info-blocking" class="text-indigo-600 dark:text-indigo-400 hover:underline">Information Blocking Policy</Link>.
                </p>
            </article>
        </div>
    </AppShell>
</template>
