<?php
/**
 * Tests for the multi-signal healthcare job classifier - specifically the
 * false-positive regressions reported on the live site (e.g. "IT Consultant"
 * being classified as a Doctor) and the genuine healthcare titles that must
 * still classify correctly.
 *
 * @package HealthcareJobs
 */

class Healthcare_Jobs_Classifier_Test extends WP_UnitTestCase {

	private function category_name_for( $title, $description = '' ) {
		$result = Healthcare_Jobs_Classifier::classify( $title, $description );
		return $result['category_name'];
	}

	/**
	 * @dataProvider non_healthcare_titles
	 */
	public function test_ambiguous_generic_titles_are_never_classified_as_doctors( $title ) {
		$this->assertNotSame( 'Doctors', $this->category_name_for( $title ), "\"{$title}\" must never classify as Doctors." );
	}

	public function non_healthcare_titles() {
		return array(
			array( 'IT Consultant' ),
			array( 'Health & Safety Consultant' ),
			array( 'Sales Consultant' ),
			array( 'Tile Sales Consultant' ),
			array( 'Management Consultant' ),
			array( 'Recruitment Consultant' ),
		);
	}

	/**
	 * @dataProvider non_healthcare_titles
	 */
	public function test_ambiguous_generic_titles_are_unclassified( $title ) {
		$result = Healthcare_Jobs_Classifier::classify( $title );
		$this->assertSame( 0, $result['term_id'], "\"{$title}\" must not match any clinical category." );
	}

	public function test_unrelated_titles_are_unclassified() {
		$result = Healthcare_Jobs_Classifier::classify( 'Warehouse Forklift Operator' );
		$this->assertSame( 0, $result['term_id'] );
	}

	public function test_gp_reception_administrator_classifies_as_receptionist_not_doctor() {
		// The exact phrase is an explicitly configured Receptionists title,
		// which must win over the ambiguous "GP" doctor rule its "GP"
		// substring would otherwise also match.
		$result = Healthcare_Jobs_Classifier::classify( 'GP Reception Administrator' );
		$this->assertSame( 'Receptionists', $result['category_name'] );
	}

	/**
	 * @dataProvider genuine_healthcare_titles
	 */
	public function test_genuine_healthcare_titles_classify_correctly( $title, $expected_category ) {
		$this->assertSame( $expected_category, $this->category_name_for( $title ), "\"{$title}\" must classify as {$expected_category}." );
	}

	public function genuine_healthcare_titles() {
		return array(
			array( 'GP', 'General Practitioners Gp' ),
			array( 'General Practitioner', 'General Practitioners Gp' ),
			array( 'Consultant Physician', 'Consultants' ),
			array( 'Specialty Doctor', 'Specialty Doctors' ),
			array( 'Registered Nurse', 'Nurses' ),
			array( 'Practice Nurse', 'Nurses' ),
			array( 'Qualified Dental Nurse', 'Dental Nurses' ),
			array( 'Pharmacist', 'Pharmacists' ),
		);
	}

	public function test_consultant_alone_with_no_clinical_context_does_not_match() {
		$result = Healthcare_Jobs_Classifier::classify( 'Consultant' );
		$this->assertNotSame( 'Consultants', $result['category_name'], '"Consultant" alone (no clinical modifier) must never classify as a doctor role.' );
	}

	public function test_consultant_with_clinical_modifier_in_description_matches() {
		$result = Healthcare_Jobs_Classifier::classify( 'Consultant', 'Leading our cardiology department as a Cardiologist.' );
		$this->assertSame( 'Consultants', $result['category_name'] );
	}

	public function test_longest_matching_title_wins() {
		// "Specialty Doctor" and "Doctor" both configured; the more specific
		// rule must win rather than the broader one.
		$result = Healthcare_Jobs_Classifier::classify( 'Specialty Doctor - Cardiology' );
		$this->assertSame( 'Specialty Doctors', $result['category_name'] );
	}
}
