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
     * Metadata storage
     *
     * @var array
     */
    private $meta;

    /**
     * Constructor.
     *
     * Initializes the event object from an ID or WP_Post object.
     *
     * @param int|WP_Post|null $input The ID of the event or a WP_Post object.
     *                                Null if creating a new event.
     * @throws Exception If the input is not a valid ID or WP_Post object.
     */
    public function __construct( $input = null ) {

        if ( $input instanceof WP_Post ) {
            $post = $input;
        } elseif ( is_int( $input ) && $input > 0 ) {
            $post = get_post( $input );
        } else {
            $post = false;
        }

        if ( $post ) {

            if ( 'decker_event' !== $post->post_type ) {
                throw new Exception( esc_attr_e( 'Invalid post type.', 'decker' ) );
            }

            $this->id          = $post->ID;
            $this->title       = (string) $post->post_title;
            $this->description = (string) $post->post_content;

            // Load all metadata once.
            $this->meta        = get_post_meta( $this->id );

            $this->assigned_users = $this->get_users( $this->meta );

            // Cargar. otras propiedades desde $this->meta.
            $this->start_date = isset( $this->meta['start_date'][0] ) ? new DateTime( $this->meta['start_date'][0] ) : null;
            $this->end_date = isset( $this->meta['end_date'][0] ) ? new DateTime( $this->meta['end_date'][0] ) : null;
            $this->location = isset( $this->meta['location'][0] ) ? $this->meta['location'][0] : '';
            $this->url = isset( $this->meta['url'][0] ) ? $this->meta['url'][0] : '';
            $this->category = isset( $this->meta['category'][0] ) ? $this->meta['category'][0] : 'bg-primary';
        }
    }

    /**
     * Método de fábrica para crear un Event con parámetros completos.
     *
     * @param int          $id             The event ID.
     * @param string       $title          The event title.
     * @param string       $description    The event description.
     * @param DateTime     $start_date     The event start date/time.
     * @param DateTime     $end_date       The event end date/time.
     * @param string       $location       The event location.
     * @param string       $url            The event URL.
     * @param string       $category       The event category.
     * @param array        $assigned_users Array of assigned user IDs.
     * @return Event
     */
    public static function create(
        $id,
        $title,
        $description,
        DateTime $start_date,
        DateTime $end_date,
        $location = '',
        $url = '',
        $category = 'bg-primary',
        array $assigned_users = array()
    ) {
        $event = new self();
        $event->id = $id;
        $event->title = $title;
        $event->description = $description;
        $event->start_date = $start_date;
        $event->end_date = $end_date;
        $event->location = $location;
        $event->url = $url;
        $event->category = $category;
        $event->assigned_users = $assigned_users;

        return $event;
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
	 * Converts an array of user IDs from meta into WP_User objects and adds a `today` property.
	 *
	 * @param array $meta Meta data array containing user IDs.
	 * @return array Array of WP_User objects with an added `today` property.
	 */
	private function get_users( array $meta ): array {
		$users = array();
		if ( isset( $meta['assigned_users'][0] ) ) {
			$user_ids = maybe_unserialize( $meta['assigned_users'][0] );

			foreach ( $user_ids as $user_id ) {
				$user = get_userdata( $user_id );
				if ( $user ) {
					$users[]     = $user;
				}
			}
		}
		return $users;
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
			'start'          => $this->start_date,
			'end'            => $this->end_date,
			'location'       => $this->location,
			'url'            => $this->url,
			'className'      => $this->category,
			'assigned_users' => $this->assigned_users,
		);
	}
}
