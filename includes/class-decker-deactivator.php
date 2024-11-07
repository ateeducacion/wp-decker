<?php
/**
 * Fired during plugin deactivation
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @package    Decker
 * @subpackage Decker/includes
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Decker_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 */
	public static function deactivate() {

		flush_rewrite_rules();
	}
}
