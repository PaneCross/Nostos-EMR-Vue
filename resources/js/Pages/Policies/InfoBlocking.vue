<script setup lang="ts">
// ─── Policies / Information Blocking ──────────────────────────────────────────
// 21st Century Cures Act § 4004 + ONC HTI-1 (45 CFR Part 171).
// Static policy page — accessible to authenticated users; participant-facing
// distribution happens via the standard Notice of Privacy Practices and EHI
// export workflows.
// ─────────────────────────────────────────────────────────────────────────────

import { Head, Link } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { DocumentTextIcon } from '@heroicons/vue/24/outline'
</script>

<template>
    <AppShell>
        <Head title="Information Blocking Policy" />

        <div class="px-6 py-8 max-w-3xl mx-auto">
            <div class="flex items-start gap-3 mb-6">
                <DocumentTextIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400 mt-0.5" />
                <div>
                    <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Information Blocking Policy</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        21st Century Cures Act § 4004 · 45 CFR Part 171 (ONC HTI-1) · Effective 2024
                    </p>
                </div>
            </div>

            <article class="prose prose-slate dark:prose-invert max-w-none text-sm leading-relaxed">
                <h2>1. Commitment</h2>
                <p>
                    This organization commits to the principles of the 21st Century Cures Act § 4004 and the
                    ONC information-blocking regulation at 45 CFR Part 171. Electronic Health Information (EHI)
                    will not be withheld, delayed, or otherwise interfered with unless an allowed exception
                    applies.
                </p>

                <h2>2. Participant (Patient) Access</h2>
                <p>
                    Participants and their personal representatives may request a complete export of their EHI at
                    any time. Staff initiate the export via the
                    <strong>Request EHI Export</strong> button on the participant's facesheet. The resulting archive
                    contains a FHIR R4 Bundle plus clinical data in machine-readable JSON, is protected by a
                    single-use, time-limited token (24-hour TTL), and is logged to the audit trail on both
                    generation and download.
                </p>

                <h2>3. Allowed Exceptions</h2>
                <p>The organization may withhold or restrict access only under the exceptions defined at 45 CFR § 171.200 et seq.:</p>
                <ul>
                    <li><strong>Preventing Harm.</strong> To prevent substantial physical harm to the participant or another person.</li>
                    <li><strong>Privacy.</strong> As required by applicable privacy law, including HIPAA (45 CFR §164.524).</li>
                    <li><strong>Security.</strong> To protect the confidentiality, integrity, and availability of EHI from identified security threats.</li>
                    <li><strong>Infeasibility.</strong> When a request is infeasible under the defined criteria.</li>
                    <li><strong>Health IT Performance.</strong> To perform necessary maintenance on the health IT systems.</li>
                    <li><strong>Content and Manner.</strong> When an alternative content or manner of response is provided per §171.301.</li>
                    <li><strong>Fees.</strong> Reasonable cost-based fees consistent with §171.302 (no fees are currently charged).</li>
                    <li><strong>Licensing.</strong> Reasonable licensing terms for interoperability elements per §171.303.</li>
                </ul>

                <h2>4. Export Format</h2>
                <p>Every EHI export includes:</p>
                <ul>
                    <li><code>fhir/Bundle.json</code> — FHIR R4 Bundle (<code>type=collection</code>) with Patient, Conditions, Allergies, Medications, Immunizations, Procedures, Observations, CarePlans, and Appointments.</li>
                    <li>Individual FHIR resource files under <code>fhir/</code> for legacy consumers.</li>
                    <li><code>clinical/</code> — Clinical notes, assessments, ADL records, SDRs, and incidents in JSON.</li>
                    <li><code>manifest.json</code> — Export metadata, record counts, timestamps, and exporter identity.</li>
                </ul>

                <h2>5. Audit Trail</h2>
                <p>
                    Every export generation and download is logged to the immutable audit trail with the requesting
                    user, participant MRN, timestamp, and token. Audit entries are retained per 42 CFR §460.210
                    (minimum 10 years).
                </p>

                <h2>6. Complaints</h2>
                <p>
                    Individuals who believe information has been improperly blocked may file a complaint with the
                    HHS Office of Inspector General at
                    <a class="text-indigo-600 dark:text-indigo-400 hover:underline"
                       href="https://oig.hhs.gov/fraud/report-fraud/"
                       target="_blank" rel="noopener">oig.hhs.gov</a>.
                </p>

                <hr class="my-6">

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Policy owner: Compliance Officer.
                    See also:
                    <Link href="/policies/npp" class="text-indigo-600 dark:text-indigo-400 hover:underline">Notice of Privacy Practices</Link>
                    ·
                    <Link href="/policies/acceptable-use" class="text-indigo-600 dark:text-indigo-400 hover:underline">Staff Acceptable-Use Policy</Link>.
                </p>
            </article>
        </div>
    </AppShell>
</template>
