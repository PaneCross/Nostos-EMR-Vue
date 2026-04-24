<?php

namespace Database\Seeders;

use App\Models\QualityMeasure;
use Illuminate\Database\Seeder;

class QualityMeasureSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['measure_id'=>'FLU','name'=>'Adult Influenza Vaccination','category'=>'hedis','numerator_definition'=>'Enrolled participants with influenza immunization in last 12 months','denominator_definition'=>'All enrolled participants','data_source'=>'emr_immunizations.vaccine_type=influenza'],
            ['measure_id'=>'PNE','name'=>'Pneumococcal Vaccination (Age 65+)','category'=>'hedis','numerator_definition'=>'Age 65+ with any pneumococcal vaccine','denominator_definition'=>'Enrolled participants age 65+','data_source'=>'emr_immunizations'],
            ['measure_id'=>'PCV','name'=>'Annual PCP Visit','category'=>'process','numerator_definition'=>'Enrolled with SOAP/progress note in last 365 days','denominator_definition'=>'All enrolled','data_source'=>'emr_clinical_notes'],
            ['measure_id'=>'A1C','name'=>'Comprehensive Diabetes Care — A1c Testing','category'=>'hedis','numerator_definition'=>'Diabetics with documented A1c in last 6 months','denominator_definition'=>'All active-problem diabetics','data_source'=>'emr_care_gaps'],
            ['measure_id'=>'DEE','name'=>'Comprehensive Diabetes Care — Eye Exam','category'=>'hedis','numerator_definition'=>'Diabetics with eye exam in last 12 months','denominator_definition'=>'All active-problem diabetics','data_source'=>'emr_care_gaps'],
            ['measure_id'=>'FALL','name'=>'Fall Without Injury Rate (90-day)','category'=>'outcome','numerator_definition'=>'Enrolled without an injurious fall in last 90 days','denominator_definition'=>'All enrolled','data_source'=>'emr_incidents'],
            ['measure_id'=>'NPP','name'=>'NPP Acknowledgment Completion','category'=>'cms_stars','numerator_definition'=>'Enrolled with acknowledged NPP','denominator_definition'=>'All enrolled','data_source'=>'emr_consent_records'],
            ['measure_id'=>'HOS','name'=>'Hospitalization Avoidance (90-day)','category'=>'outcome','numerator_definition'=>'Enrolled without hospitalization in last 90 days','denominator_definition'=>'All enrolled','data_source'=>'emr_incidents'],
            ['measure_id'=>'AD', 'name'=>'Advance Directive on File','category'=>'cms_stars','numerator_definition'=>'Enrolled with advance_directive_status != none','denominator_definition'=>'All enrolled','data_source'=>'emr_participants'],
        ];
        foreach ($rows as $r) {
            QualityMeasure::updateOrCreate(['measure_id' => $r['measure_id']], $r);
        }
        $this->command?->info('    Quality measures seeded (' . count($rows) . ').');
    }
}
