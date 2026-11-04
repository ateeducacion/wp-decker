<?php
/**
 * WordPress principals limited to the authenticated account.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** No directory of unrelated site users is exposed. */
class Decker_Dav_Principals extends Sabre\DAVACL\PrincipalBackend\AbstractBackend {
	/** List accessible principals.
	 *
	 * @param string $prefix_path Prefix.
	 * @return array
	 */
	public function getPrincipalsByPrefix( $prefix_path ) {
		$principal = $this->getPrincipalByPath( 'principals/' . wp_get_current_user()->user_login );
		return 'principals' === $prefix_path && $principal ? array( $principal ) : array();
	}
	/** Resolve the current principal.
	 *
	 * @param string $path Path.
	 * @return array|null
	 */
	public function getPrincipalByPath( $path ) {
		$user = wp_get_current_user();
		if ( Decker_Dav::allowed() && 'principals/' . $user->user_login === $path ) {
			return array(
				'uri' => $path,
				'{DAV:}displayname' => $user->display_name,
			);
		}
		return null;
	}
	/** Principal properties are managed in WordPress.
	 *
	 * @param string              $path Path.
	 * @param Sabre\DAV\PropPatch $prop_patch Patch.
	 */
	public function updatePrincipal( $path, Sabre\DAV\PropPatch $prop_patch ) {}
	/** No address search or scheduling.
	 *
	 * @param string $prefix_path Prefix.
	 * @param array  $search_properties Properties.
	 * @param string $test Match mode.
	 * @return array
	 */
	public function searchPrincipals( $prefix_path, array $search_properties, $test = 'allof' ) {
		return array(); }
	/** No DAV groups.
	 *
	 * @param string $principal Principal.
	 * @return array
	 */
	public function getGroupMemberSet( $principal ) {
		return array(); }
	/** No DAV groups.
	 *
	 * @param string $principal Principal.
	 * @return array
	 */
	public function getGroupMembership( $principal ) {
		return array(); }
	/** Group writes are forbidden.
	 *
	 * @param string $principal Principal.
	 * @param array  $members Members.
	 * @throws Sabre\DAV\Exception\Forbidden Invalid or forbidden calendar operation.
	 */
	public function setGroupMemberSet( $principal, array $members ) {
		throw new Sabre\DAV\Exception\Forbidden(); }
}
