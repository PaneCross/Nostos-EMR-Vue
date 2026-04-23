<?php

namespace Database\Seeders;

use App\Models\NoteTemplate;
use Illuminate\Database\Seeder;

/**
 * Phase B7 — Ships 11 system-default note templates (tenant_id NULL, is_system true).
 * Available to every tenant. Idempotent via updateOrCreate on (tenant_id=null, name).
 */
class SystemNoteTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            NoteTemplate::updateOrCreate(
                ['tenant_id' => null, 'name' => $tpl['name']],
                array_merge($tpl, ['is_system' => true]),
            );
        }
        $this->command?->info('    System note templates seeded (' . count($this->templates()) . ').');
    }

    private function templates(): array
    {
        return [
            [
                'name' => 'SOAP (Generic)',
                'note_type' => 'soap',
                'department' => 'primary_care',
                'body_markdown' => <<<MD
# SOAP Note — {{participant.name}} ({{participant.mrn}})
Date: {{today}} · Provider: {{provider.name}}

## Subjective
Chief complaint:

HPI:

## Objective
Vitals: {{latest_vitals}}

Active medications:
{{active_meds_list}}

## Assessment
Active problems:
{{problem_list}}

## Plan

MD,
            ],
            [
                'name' => 'Annual Comprehensive Assessment',
                'note_type' => 'soap',
                'department' => 'primary_care',
                'body_markdown' => <<<MD
# Annual Comprehensive Assessment — {{participant.name}}
Age: {{participant.age}} · DOB: {{participant.dob}} · MRN: {{participant.mrn}}
Provider: {{provider.name}} · Date: {{today}}

## History
- Medical:
- Surgical:
- Social:
- Family:

## Review of Systems

## Physical Exam
Vitals: {{latest_vitals}}

## Problem List
{{problem_list}}

## Medications
{{active_meds_list}}

## Assessment & Plan

## Advance Directive Review
MD,
            ],
            [
                'name' => 'Post-Hospital Follow-up',
                'note_type' => 'soap',
                'department' => 'primary_care',
                'body_markdown' => <<<MD
# Post-Hospital Follow-up — {{participant.name}}
Date: {{today}} · Provider: {{provider.name}}

## Discharge Summary Review
- Admission date:
- Discharge date:
- Primary diagnosis:
- Procedures:

## Medication Reconciliation
Current active meds:
{{active_meds_list}}

- New meds to add:
- Meds discontinued:
- Dose changes:

## Assessment
Vitals today: {{latest_vitals}}

## Plan
- F/u with specialty:
- Next PACE visit:
MD,
            ],
            [
                'name' => 'Diabetes Management',
                'note_type' => 'soap',
                'department' => 'primary_care',
                'body_markdown' => <<<MD
# Diabetes Management — {{participant.name}} ({{today}})
Provider: {{provider.name}}

## Subjective
- Hypoglycemic episodes (last 30d):
- Polyuria / polydipsia:
- Symptoms of neuropathy / vision change:

## Objective
- Fingerstick log review:
- Last A1C:
- Last foot exam:
- Vitals: {{latest_vitals}}

## Assessment
- Glycemic control: [controlled / uncontrolled]
- Complications:

## Plan
- Meds adjustment:
- Goal A1C:
- Next labs:
MD,
            ],
            [
                'name' => 'CHF Check-in',
                'note_type' => 'soap',
                'department' => 'primary_care',
                'body_markdown' => <<<MD
# CHF Check-in — {{participant.name}} ({{today}})

## Subjective
- SOB / orthopnea / PND:
- Weight change (lbs from baseline):
- Edema:
- Med compliance:

## Objective
- Vitals: {{latest_vitals}}
- JVP / crackles / edema exam:
- Weight today:

## Assessment
- NYHA class:
- Volume status:

## Plan
- Diuretic adjustment:
- Next weight check:
MD,
            ],
            [
                'name' => 'Pain Assessment',
                'note_type' => 'soap',
                'department' => 'primary_care',
                'body_markdown' => <<<MD
# Pain Assessment — {{participant.name}}
Date: {{today}} · Provider: {{provider.name}}

## Pain Characterization
- Location:
- Quality:
- Severity (0-10):
- Frequency / duration:
- Aggravating / alleviating:

## Current Pain Regimen
{{active_meds_list}}

## Function / Impact
- ADL impact:
- Sleep impact:

## Plan
MD,
            ],
            [
                'name' => 'Behavioral Health Progress',
                'note_type' => 'behavioral_health',
                'department' => 'behavioral_health',
                'body_markdown' => <<<MD
# BH Progress Note — {{participant.name}}
Session date: {{today}} · Provider: {{provider.name}}

## Subjective / Interval History

## Mental Status Exam
- Appearance:
- Behavior:
- Mood / Affect:
- Thought:
- Insight / Judgment:

## Assessment

## Plan
- Therapy type / modality:
- Med changes:
- Next session:
MD,
            ],
            [
                'name' => 'Wound Care',
                'note_type' => 'progress_nursing',
                'department' => 'home_care',
                'body_markdown' => <<<MD
# Wound Care Note — {{participant.name}} ({{today}})

## Wound Description
- Location:
- Stage / type:
- Measurements (L × W × D):
- Drainage / odor:
- Surrounding skin:

## Intervention
- Cleansing:
- Dressing:
- Product used:

## Next Change
MD,
            ],
            [
                'name' => 'Dietary Consult',
                'note_type' => 'dietary',
                'department' => 'dietary',
                'body_markdown' => <<<MD
# Dietary Consultation — {{participant.name}}
Date: {{today}} · Dietitian: {{provider.name}}

## Referral Reason

## Current Intake / Diet
- Typical meals:
- Food preferences / aversions:
- Chewing / swallowing issues:

## Labs / Weight Trend

## Plan
- Diet order:
- Supplement:
- Follow-up:
MD,
            ],
            [
                'name' => 'Therapy Progress (PT/OT/ST)',
                'note_type' => 'therapy_pt',
                'department' => 'therapies',
                'body_markdown' => <<<MD
# Therapy Progress Note — {{participant.name}}
Date: {{today}} · Therapist: {{provider.name}}

## Goals (from POC)

## Interventions Today

## Response / Progress

## Plan
- Frequency:
- Re-eval date:
MD,
            ],
            [
                'name' => 'Social Work Intake',
                'note_type' => 'social_work',
                'department' => 'social_work',
                'body_markdown' => <<<MD
# Social Work Intake — {{participant.name}}
Date: {{today}} · SW: {{provider.name}}

## Living Situation

## Support System / Caregiver

## Financial / Insurance

## Legal / Advance Directive Status

## Psychosocial Assessment

## Plan / Referrals
MD,
            ],
        ];
    }
}
