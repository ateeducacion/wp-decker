<?php
/**
 * Random choices for the demo data generators.
 *
 * Every generator draws through this class so the randomness helpers live in
 * one place instead of being duplicated per content type.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Demo_Randomizer
 */
class Decker_Demo_Randomizer {

	/**
	 * Generates a random hexadecimal color.
	 *
	 * @return string Color in hexadecimal format (e.g., #a3f4c1).
	 */
	public function generate_random_color() {
		return sprintf( '#%06X', $this->custom_rand( 0, 0xFFFFFF ) );
	}

	/**
	 * Selects random elements from an array.
	 *
	 * @param array $array Array to select elements from.
	 * @param int   $number Number of elements to select.
	 * @return array Selected elements.
	 */
	public function wp_rand_elements( $array, $number ) {
		if ( $number >= count( $array ) ) {
			return $array;
		}
		$keys = array_rand( $array, $number );
		if ( 1 == $number ) {
			return array( $array[ $keys ] );
		}
		$selected = array();
		foreach ( $keys as $key ) {
			$selected[] = $array[ $key ];
		}
		return $selected;
	}

	/**
	 * Generates a random boolean value based on a probability.
	 *
	 * @param float $true_probability Probability of returning true (between 0 and 1).
	 * @return bool
	 */
	public function random_boolean( $true_probability = 0.5 ) {
		return ( $this->custom_rand() / mt_getrandmax() ) < $true_probability;
	}

	/**
	 * Generates a random date between two given dates.
	 *
	 * @param string $start Start date (format recognized by strtotime).
	 * @param string $end End date (format recognized by strtotime).
	 * @return DateTime Randomly generated date.
	 */
	public function random_date( $start, $end ) {
		$min = strtotime( $start );
		$max = strtotime( $end );
		$timestamp = $this->custom_rand( $min, $max );
		return ( new DateTime() )->setTimestamp( $timestamp );
	}

	/**
	 * Selects a random stack.
	 *
	 * @return string One of these values: 'to-do', 'in-progress', 'done'.
	 */
	public function random_stack() {
		$stacks = array( 'to-do', 'in-progress', 'done' );
		return $stacks[ array_rand( $stacks ) ];
	}

	/**
	 * Custom random number generator for WordPress Playground.
	 *
	 * @param int $min Minimum value.
	 * @param int $max Maximum value.
	 * @return int Random number between $min and $max.
	 */
	public function custom_rand( $min = 0, $max = PHP_INT_MAX ) {

		return wp_rand( $min, $max );
	}
}
