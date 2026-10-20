<?php

namespace Database\Seeders;

use App\Models\TaxonomyTerm;
use Illuminate\Database\Seeder;

/**
 * Healthcare industry pack: the vocabulary for organization profiles and
 * for deterministic applicability pre-screening.
 *
 * `cfr_parts` on programs are SCREENING HINTS used to flag documents that
 * amend those parts. They are not a determination that a rule applies to
 * any organization; the profile, the AI screen and a human reviewer decide.
 * Idempotent: safe to run on every deploy.
 */
class HealthcareTaxonomySeeder extends Seeder
{
    private const INDUSTRY = 'healthcare';

    public function run(): void
    {
        $this->seedKind('org_type', [
            ['hospital_health_system', 'Hospital or health system'],
            ['physician_group', 'Physician group or clinic'],
            ['commercial_health_plan', 'Commercial health insurer'],
            ['medicare_advantage_org', 'Medicare Advantage organization'],
            ['part_d_sponsor', 'Medicare Part D sponsor'],
            ['medicaid_mco', 'Medicaid managed care organization'],
            ['marketplace_issuer', 'ACA Marketplace (QHP) issuer'],
            ['pharmacy', 'Pharmacy or PBM'],
            ['health_tech_vendor', 'Healthcare technology company'],
            ['compliance_consultancy', 'Healthcare compliance consulting firm'],
        ]);

        $this->seedKind('program', [
            ['medicare_advantage', 'Medicare Advantage (Part C)', ['cfr_parts' => ['42 CFR 422']]],
            ['medicare_part_d', 'Medicare Part D', ['cfr_parts' => ['42 CFR 423']]],
            ['medicare_hospital_cop', 'Medicare hospital Conditions of Participation', ['cfr_parts' => ['42 CFR 482']]],
            ['medicaid_managed_care', 'Medicaid managed care', ['cfr_parts' => ['42 CFR 438']]],
            ['chip', "Children's Health Insurance Program", ['cfr_parts' => ['42 CFR 457']]],
            ['pace', 'PACE', ['cfr_parts' => ['42 CFR 460']]],
            ['aca_marketplace', 'ACA Marketplace', ['cfr_parts' => ['45 CFR 155', '45 CFR 156']]],
            ['hipaa_privacy_security', 'HIPAA Privacy, Security and Breach Notification', ['cfr_parts' => ['45 CFR 160', '45 CFR 164']]],
            ['hipaa_transactions', 'HIPAA administrative transactions and code sets', ['cfr_parts' => ['45 CFR 162']]],
            ['part2_confidentiality', 'Confidentiality of SUD patient records (Part 2)', ['cfr_parts' => ['42 CFR 2']]],
            ['onc_certification', 'ONC Health IT Certification Program', ['cfr_parts' => ['45 CFR 170']]],
            ['information_blocking', 'Information blocking', ['cfr_parts' => ['45 CFR 171']]],
            ['oig_program_integrity', 'OIG exclusions and anti-kickback safe harbors', ['cfr_parts' => ['42 CFR 1001']]],
        ]);

        $this->seedKind('function', [
            ['claims', 'Claims operations'],
            ['enrollment_eligibility', 'Enrollment and eligibility'],
            ['utilization_management', 'Utilization management and prior authorization'],
            ['appeals_grievances', 'Appeals and grievances'],
            ['provider_network', 'Provider network and credentialing'],
            ['quality_star_ratings', 'Quality and Star Ratings'],
            ['risk_adjustment', 'Risk adjustment'],
            ['pharmacy_benefits', 'Pharmacy benefits'],
            ['member_services', 'Member services and communications'],
            ['marketing', 'Marketing and sales'],
            ['privacy', 'Privacy'],
            ['information_security', 'Information security'],
            ['interoperability', 'Interoperability and health IT'],
            ['finance_reporting', 'Finance and regulatory reporting'],
            ['compliance_program', 'Compliance program and FWA'],
        ]);

        $this->seedKind('topic', [
            ['prior_authorization', 'Prior authorization'],
            ['interoperability_apis', 'Interoperability and patient access APIs'],
            ['price_transparency', 'Price transparency'],
            ['star_ratings', 'Star Ratings methodology'],
            ['payment_policy', 'Payment and rate policy'],
            ['network_adequacy', 'Network adequacy'],
            ['marketing_communications', 'Marketing and communications'],
            ['privacy_breach', 'Privacy and breach notification'],
            ['cybersecurity', 'Cybersecurity'],
            ['fraud_waste_abuse', 'Fraud, waste and abuse'],
            ['coverage_benefits', 'Coverage and benefits'],
        ]);

        $this->seedKind('state', array_map(
            fn (array $s) => [$s[0], $s[1]],
            self::STATES,
        ));
    }

    /** @param array<int, array{0: string, 1: string, 2?: array<string, mixed>}> $terms */
    private function seedKind(string $kind, array $terms): void
    {
        foreach ($terms as $i => $term) {
            TaxonomyTerm::updateOrCreate(
                ['industry' => self::INDUSTRY, 'kind' => $kind, 'code' => $term[0]],
                ['label' => $term[1], 'metadata' => $term[2] ?? null, 'sort_order' => $i],
            );
        }
    }

    private const STATES = [
        ['AL', 'Alabama'], ['AK', 'Alaska'], ['AZ', 'Arizona'], ['AR', 'Arkansas'],
        ['CA', 'California'], ['CO', 'Colorado'], ['CT', 'Connecticut'], ['DE', 'Delaware'],
        ['DC', 'District of Columbia'], ['FL', 'Florida'], ['GA', 'Georgia'], ['HI', 'Hawaii'],
        ['ID', 'Idaho'], ['IL', 'Illinois'], ['IN', 'Indiana'], ['IA', 'Iowa'],
        ['KS', 'Kansas'], ['KY', 'Kentucky'], ['LA', 'Louisiana'], ['ME', 'Maine'],
        ['MD', 'Maryland'], ['MA', 'Massachusetts'], ['MI', 'Michigan'], ['MN', 'Minnesota'],
        ['MS', 'Mississippi'], ['MO', 'Missouri'], ['MT', 'Montana'], ['NE', 'Nebraska'],
        ['NV', 'Nevada'], ['NH', 'New Hampshire'], ['NJ', 'New Jersey'], ['NM', 'New Mexico'],
        ['NY', 'New York'], ['NC', 'North Carolina'], ['ND', 'North Dakota'], ['OH', 'Ohio'],
        ['OK', 'Oklahoma'], ['OR', 'Oregon'], ['PA', 'Pennsylvania'], ['PR', 'Puerto Rico'],
        ['RI', 'Rhode Island'], ['SC', 'South Carolina'], ['SD', 'South Dakota'], ['TN', 'Tennessee'],
        ['TX', 'Texas'], ['UT', 'Utah'], ['VT', 'Vermont'], ['VA', 'Virginia'],
        ['WA', 'Washington'], ['WV', 'West Virginia'], ['WI', 'Wisconsin'], ['WY', 'Wyoming'],
    ];
}
