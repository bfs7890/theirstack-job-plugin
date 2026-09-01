<?php
/**
 * Healthcare job classifier.
 *
 * Maps a raw job title/description onto one of Directorist's real
 * `at_biz_dir-category` taxonomy terms, using the admin-configured rules
 * from Healthcare_Jobs_Categories. This replaces naive substring matching
 * (which classified any title containing "Consultant" or "GP" as a doctor
 * role, producing false positives like "IT Consultant" or "GP Reception
 * Administrator") with a multi-signal approach:
 *
 *  - Unambiguous titles (Dentist, Pharmacist, Physiotherapist, ...) match
 *    on their own, as a whole word/phrase.
 *  - Ambiguous titles (Consultant, GP, Expert Witness) additionally require
 *    at least one "context term" (a clinical modifier, e.g. "Cardiologist",
 *    or an independent signal like "Medical") to also appear in the title
 *    or description.
 *  - Exclusion terms veto a match outright even when the title/context
 *    matched, e.g. "Consultant" + "IT" never classifies as a clinical role.
 *
 * Entirely data-driven (see Healthcare_Jobs_Categories::get_default_data())
 * so an administrator can refine categories and rules from the Categories
 * screen without a code change.
 *
 * @package HealthcareJobs
 */

defined( 'ABSPATH' ) || exit;

class Healthcare_Jobs_Classifier {

	/**
	 * Cached classification rules for the lifetime of one request/import
	 * run, to avoid re-querying the database for every job.
	 *
	 * @var array|null
	 */
	private static $rules_cache = null;

	/**
	 * Clears the cached rule set. Call after editing categories/titles in
	 * the same request (e.g. right after a settings save) if a fresh
	 * classification is needed immediately.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		self::$rules_cache = null;
	}

	/**
	 * Classifies a job into a Directorist category term.
	 *
	 * @param string $title       Job title.
	 * @param string $description Job description (used only as a
	 *                            corroborating signal for ambiguous titles).
	 * @return array{term_id:int, category_name:string, matched_title:string}
	 *               term_id is 0 when nothing matched.
	 */
	public static function classify( $title, $description = '' ) {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			return self::no_match();
		}

		$haystack_title = self::normalise( $title );
		$haystack_full  = self::normalise( $title . ' ' . wp_strip_all_tags( (string) $description ) );

		$rules = self::get_rules();

		$best = null;

		foreach ( $rules as $rule ) {
			if ( ! self::contains_term( $haystack_title, $rule['title'] ) ) {
				continue;
			}

			// An exclusion term veto's this specific rule's match, but a
			// different rule may still legitimately match the same title
			// (e.g. "GP Receptionist" is vetoed for the GP/doctor rule by
			// its "Receptionist" exclusion, while separately matching the
			// Receptionists category's own "Receptionist" rule).
			if ( self::matches_any( $haystack_title, $rule['exclusion_terms'] ) ) {
				continue;
			}

			if ( $rule['is_ambiguous'] && ! self::matches_any( $haystack_full, $rule['context_terms'] ) ) {
				continue;
			}

			// Prefer the most specific (longest) matching title, so
			// "Specialty Doctor" wins over a broader "Doctor" rule when a
			// title matches both.
			if ( null === $best || strlen( $rule['title'] ) > strlen( $best['title'] ) ) {
				$best = $rule;
			}
		}

		if ( null === $best ) {
			return self::no_match();
		}

		return array(
			'term_id'       => $best['directorist_term_id'],
			'category_name' => $best['category_name'],
			'matched_title' => $best['title'],
		);
	}

	/**
	 * Convenience wrapper returning just the term ID (0 = unclassified).
	 *
	 * @param string $title       Job title.
	 * @param string $description Job description.
	 * @return int
	 */
	public static function classify_to_term_id( $title, $description = '' ) {
		return self::classify( $title, $description )['term_id'];
	}

	/**
	 * Loads and caches the classification rules.
	 *
	 * @return array
	 */
	private static function get_rules() {
		if ( null === self::$rules_cache ) {
			self::$rules_cache = Healthcare_Jobs_Categories::get_classification_rules();
		}
		return self::$rules_cache;
	}

	/**
	 * Lowercases and collapses whitespace for matching.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function normalise( $text ) {
		return strtolower( preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	/**
	 * Whether $term appears in $haystack as a whole word/phrase.
	 *
	 * @param string $haystack Already-normalised (lowercase) text.
	 * @param string $term     Term to look for (any case).
	 * @return bool
	 */
	private static function contains_term( $haystack, $term ) {
		$term = trim( (string) $term );
		if ( '' === $term ) {
			return false;
		}
		// \b doesn't work well around "&" (e.g. "A&E"), so fall back to a
		// simple substring check for terms containing non-word characters.
		if ( preg_match( '/[^\w\s]/', $term ) ) {
			return false !== strpos( $haystack, strtolower( $term ) );
		}
		$pattern = '/\b' . preg_quote( strtolower( $term ), '/' ) . '\b/u';
		return 1 === preg_match( $pattern, $haystack );
	}

	/**
	 * Whether any of $terms appears in $haystack.
	 *
	 * @param string $haystack Already-normalised (lowercase) text.
	 * @param array  $terms    Candidate terms.
	 * @return bool
	 */
	private static function matches_any( $haystack, array $terms ) {
		foreach ( $terms as $term ) {
			if ( self::contains_term( $haystack, $term ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The "no classification" result shape.
	 *
	 * @return array
	 */
	private static function no_match() {
		return array(
			'term_id'       => 0,
			'category_name' => '',
			'matched_title' => '',
		);
	}
}
