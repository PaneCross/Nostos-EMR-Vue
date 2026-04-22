<script setup lang="ts">
// ─── Policies / Notice of Privacy Practices ──────────────────────────────────
// HIPAA Privacy Rule 45 CFR §164.520.
// Phase 5 (MVP roadmap).
// ─────────────────────────────────────────────────────────────────────────────

import { Head, Link } from '@inertiajs/vue3'
import AppShell from '@/Layouts/AppShell.vue'
import { ShieldCheckIcon } from '@heroicons/vue/24/outline'
</script>

<template>
    <AppShell>
        <Head title="Notice of Privacy Practices" />

        <div class="px-6 py-8 max-w-3xl mx-auto">
            <div class="flex items-start gap-3 mb-6">
                <ShieldCheckIcon class="w-7 h-7 text-indigo-600 dark:text-indigo-400 mt-0.5" />
                <div>
                    <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Notice of Privacy Practices</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">HIPAA Privacy Rule · 45 CFR §164.520</p>
                </div>
            </div>

            <article class="prose prose-slate dark:prose-invert max-w-none text-sm leading-relaxed">
                <p class="italic">
                    This Notice describes how medical information about you may be used and disclosed and how you
                    can get access to this information. Please review it carefully.
                </p>

                <h2>1. Uses and Disclosures Without Your Authorization</h2>
                <p>We may use and disclose your Protected Health Information (PHI) for the following purposes without asking your permission:</p>
                <ul>
                    <li><strong>Treatment.</strong> To provide, coordinate, or manage your health care.</li>
                    <li><strong>Payment.</strong> To bill and collect payment from CMS, Medicaid, or other responsible parties.</li>
                    <li><strong>Health Care Operations.</strong> For internal quality improvement, training, audits, and other operational activities.</li>
                    <li><strong>Required by Law.</strong> When disclosure is required by federal, state, or local law.</li>
                    <li><strong>Public Health and Safety.</strong> To report disease outbreaks, abuse/neglect, or serious threats to safety.</li>
                </ul>

                <h2>2. Uses and Disclosures That Require Your Authorization</h2>
                <p>
                    Most uses and disclosures of psychotherapy notes, uses for marketing, and disclosures that
                    constitute a sale of your PHI require your written authorization. You may revoke any
                    authorization in writing at any time.
                </p>

                <h2>3. Your Rights</h2>
                <ul>
                    <li><strong>Right to Access.</strong> You may inspect and obtain a copy of your PHI in our designated record set. Use the <strong>Request EHI Export</strong> tool on the facesheet to receive a complete electronic copy (45 CFR §164.524).</li>
                    <li><strong>Right to Amend.</strong> You may request we amend inaccurate or incomplete PHI (§164.526).</li>
                    <li><strong>Right to an Accounting of Disclosures.</strong> You may request a list of disclosures made in the prior six years (§164.528).</li>
                    <li><strong>Right to Request Restrictions.</strong> You may request restrictions on certain uses and disclosures (§164.522).</li>
                    <li><strong>Right to Confidential Communications.</strong> You may request we contact you at a specific location or by a specific method.</li>
                    <li><strong>Right to a Paper Copy of This Notice.</strong> Even if you agree to receive this notice electronically, you may request a paper copy at any time.</li>
                </ul>

                <h2>4. Our Obligations</h2>
                <p>
                    We are required by law to maintain the privacy and security of your PHI, provide this notice of our
                    legal duties and privacy practices, and notify you if a breach of unsecured PHI occurs.
                </p>

                <h2>5. Complaints</h2>
                <p>
                    You may file a complaint with our Privacy Officer or with the
                    U.S. Department of Health &amp; Human Services, Office for Civil Rights, at
                    <a class="text-indigo-600 dark:text-indigo-400 hover:underline"
                       href="https://www.hhs.gov/ocr/filing-a-complaint.html"
                       target="_blank" rel="noopener">hhs.gov/ocr</a>.
                    You will not be retaliated against for filing a complaint.
                </p>

                <h2>6. Changes to This Notice</h2>
                <p>
                    We reserve the right to change the terms of this Notice and to make the new Notice provisions
                    effective for all PHI we maintain. The current Notice is always posted on this page.
                </p>

                <hr class="my-6">

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Effective date: 2026-04-21. Privacy Officer contact is available in System Settings.
                    See also:
                    <Link href="/policies/info-blocking" class="text-indigo-600 dark:text-indigo-400 hover:underline">Information Blocking Policy</Link>.
                </p>
            </article>
        </div>
    </AppShell>
</template>
