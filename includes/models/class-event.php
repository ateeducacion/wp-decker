<?php
/**
 * The Event model class
 *
 * @link       https://github.com/ateeducacion/wp-decker
 * @since      1.0.0
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 */

/**
 * The Event model class
 */
class Event {

	/**
	 * The event ID
	 *
	 * @var int
	 */
	private $id;

	/**
	 * The event title
	 *
	 * @var string
	 */
	private $title;

	/**
	 * The event description
	 *
	 * @var string
	 */
	private $description;

	/**
	 * The event start date/time
	 *
	 * @var DateTime
	 */
	private $start_date;

	/**
	 * The event end date/time
	 *
	 * @var DateTime
	 */
	private $end_date;

	/**
	 * The event location
	 *
	 * @var string
	 */
	private $location;

	/**
	 * The event URL
	 *
	 * @var string
	 */
	private $url;

	/**
	 * The event category
	 *
	 * @var string
	 */
	private $category;

	/**
	 * Array of assigned user IDs
	 *
	 * @var array
	 */
	private $assigned_users;

	/**
	 * Constructor
	 *
	 * @param int      $id             The event ID.
	 * @param string   $title          The event title.
	 * @param string   $description    The event description.
	 * @param DateTime $start_date     The event start date/time.
	 * @param DateTime $end_date       The event end date/time.
	 * @param string   $location       The event location.
	 * @param string   $url            The event URL.
	 * @param string   $category       The event category.
	 * @param array    $assigned_users Array of assigned user IDs.
	 */
	public function __construct(
		$id,
		$title,
		$description,
		DateTime $start_date,
		DateTime $end_date,
		$location = '',
		$url = '',
		$category = 'bg-primary',
		$assigned_users = array()
	) {
		$this->id = $id;
		$this->title = $title;
		$this->description = $description;
		$this->start_date = $start_date;
		$this->end_date = $end_date;
		$this->location = $location;
		$this->url = $url;
		$this->category = $category;
		$this->assigned_users = $assigned_users;
	}

	/**
	 * Get the event ID
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get the event title
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->title;
	}

	/**
	 * Get the event description
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Get the event start date
	 *
	 * @return DateTime
	 */
	public function get_start_date() {
		return $this->start_date;
	}

	/**
	 * Get the event end date
	 *
	 * @return DateTime
	 */
	public function get_end_date() {
		return $this->end_date;
	}

	/**
	 * Get the event location
	 *
	 * @return string
	 */
	public function get_location() {
		return $this->location;
	}

	/**
	 * Get the event URL
	 *
	 * @return string
	 */
	public function get_url() {
		return $this->url;
	}

	/**
	 * Get the event category
	 *
	 * @return string
	 */
	public function get_category() {
		return $this->category;
	}

	/**
	 * Get the assigned users
	 *
	 * @return array
	 */
	public function get_assigned_users() {
		return $this->assigned_users;
	}

	/**
	 * Convert to array format
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'id'             => $this->id,
			'title'          => $this->title,
			'description'    => $this->description,
			'start'          => $this->start_date->format( 'Y-m-d\TH:i:s' ),
			'end'            => $this->end_date->format( 'Y-m-d\TH:i:s' ),
			'location'       => $this->location,
			'url'            => $this->url,
			'className'      => $this->category,
			'assigned_users' => $this->assigned_users,
		);
	}
}
