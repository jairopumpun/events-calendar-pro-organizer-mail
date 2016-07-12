<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Used in email template file
$lmbk_event_info = array();

/**
 * A custom Event Organizer Email
 *
 * @since 1.0.1
 * @extends \WC_Email
 */
class WC_Event_Organizer_Email extends WC_Email {

	/**
	 * Set Constructor Function
	 *
	 * @since 1.0.1
	 */
	public function __construct() {

		// Instantiating properties
		$this->id = 'wc_event_organizer_email_lmbk';
		$this->title = 'Event Organizer Email';
		$this->description = 'Sent to the organizer of an event';

		// Instatntiating default properties, overridden in WooCommerce Settings
		$this->heading = 'New ticket activity';
		$this->subject = 'New ticket activity';

		// Template locations defined
		$this->template_base = WC_EVENT_ORGANIZER_TEMPLATE_DIR;
		$this->template_html  = 'emails/event-organizer-notification.php';
		$this->template_plain = 'emails/plain/event-organizer-notification.php';

		// Adds an option to resend this mail from Order Edit Screen
		add_action( 'woocommerce_resend_order_emails_available', array( $this, 'add_resend_event_organizer_action' ) );

		// Notification hooks copied from the New Order. Should trigger as soon as order is made
		add_action( 'woocommerce_order_status_pending_to_processing_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_pending_to_completed_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_to_processing_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_to_completed_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_to_on-hold_notification', array( $this, 'trigger' ) );

		// Parent Constructor
		parent::__construct();
	}

	/**
	 * Determine if the email should actually be sent and setup email merge variables
	 *
	 * @since 1.0.1
	 * @param int $order_id
	 */
	public function trigger( $order_id ) {
		
		
		// Exit if no order id present
		if ( ! $order_id ) return;


		// Exit if his email is not enabled
		if ( ! $this->is_enabled() )
			return;

		// Instantiate Order Object
		$this->object = new WC_Order();
		$this->object->get_order( $order_id );

		// Set up a few variables for use later
		global $lmbk_event_info;
		$orderItems = $this->object->get_items();
		$organizersToNotify = array();
		$ticketLog = array();


		// Make sure Tribe Events Plugin is activated
		if ( is_plugin_active('event-tickets/event-tickets.php') || is_plugin_active('event-tickets-plus/event-tickets-plus.php') ) {
			//$tribe_event = Tribe__Events__Tickets__Woo__Main::get_instance();
			
			// Loop over order items / tickets
			if ( !empty( $orderItems ) ) {
				foreach( $orderItems as $orderID => $orderMeta ) {

					$product_id = $orderMeta['product_id'];
					$event_id = get_post_meta( $product_id, '_tribe_wooticket_for_event', true );

					// If this is not an event ticket, continue
					if ( !$event_id ) continue;

					// If this ticket has not been added to our custom array, add now
					if ( !isset($ticketLog[$product_id]) ) {
						$ticketLog[$product_id] = array (
							'name' => get_the_title( $product_id  ),
							'qty' => 0,
						);
					}

					// Update this ticket's quantity ( In case multiple lines of the same ticket exists)
					$ticketLog[$product_id]['qty'] = $ticketLog[$product_id]['qty'] + $orderMeta['qty'];

					// Get Event Meta Array and all Organizers added for this event
					$eventMeta = tribe_get_event_meta( $event_id );
					$eventOrganizerIDs = $eventMeta['_EventOrganizerID'];

					error_log('there are ' . count($eventOrganizerIDs) . ' orderganizers for this event');

					// Loop over Organizers for this event
					foreach( $eventOrganizerIDs as $event_organizer_id ) {

						// Collect this Organizer's email address
						$eventOrganizerEmail = tribe_get_organizer_email( $event_organizer_id );

						// If the event, associated with this looping line item ( product ) has not yet been added to the array...
						if ( !isset($organizersToNotify[$eventOrganizerEmail][$event_id] ) )
							$organizersToNotify[$eventOrganizerEmail][$event_id] = array(
								'name' => get_the_title($event_id),
								'edit_post' => get_edit_post_link($event_id),
								'tickets' => array(
									$product_id => $ticketLog[$product_id]
								)
							);
						else { // ... just add the the ticket(s) to already existing events
							$organizersToNotify[$eventOrganizerEmail][$event_id]['tickets'][$product_id] = $ticketLog[$product_id];
						}
					}
				}
			}

			// Replace variables in the subject/headings
			$this->find[] = '{order_date}';
			$this->replace[] = date_i18n( woocommerce_date_format(), strtotime( $this->object->order_date ) );

			$this->find[] = '{order_number}';
			$this->replace[] = $this->object->get_order_number();			

			// Now loop over all collected organizers and send each one email for all tickets assigned to this organizer
			if ( !empty( $organizersToNotify) ) {
				foreach( $organizersToNotify as $organizer_email => $event_info ) {
					$lmbk_event_info = array (
						'order_link' => get_edit_post_link( $order_id ),
						'meta' => $event_info,
						'show_links' => ( $this->get_option( 'edit_links' ) == 'no' ) ? false : true
					);

					// Establish who to mail
					$this->find_validate_recipient( $organizer_email, $this->get_option( 'recipient' ) );
										
					$this->send( $organizer_email, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );	
				}
			}
		}		
	}

	/**
	 * find_validate_recipient function.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	private function find_validate_recipient( &$organizer_email, $recipient_selection ) {
		switch( $recipient_selection ) {
			case 'recipient_other':
				$other_recipient = $this->get_option( 'other_recipient' );
				if ( is_email( $other_recipient ) ) $organizer_email = $other_recipient;
				else $organizer_email = get_bloginfo( 'admin_email' );
				break;										
			case 'recipient_new_order':
				$new_order_email = new WC_Email_New_Order();
				$organizer_email = $new_order_email->get_option( 'recipient' );
			case 'recipient_event_specific':
				if ( is_email( $organizer_email ) ) break;						
			case 'recipient_site_admin':
				$organizer_email = get_bloginfo( 'admin_email' );
				break;
		}
	}

	/**
	 * get_content_html function.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	public function get_content_html() {
		ob_start();
		if ( function_exists( 'woocommerce_get_template') ) {
			woocommerce_get_template( $this->template_html, array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading()
			), ' ', WC_EVENT_ORGANIZER_TEMPLATE_DIR );
		}
		return ob_get_clean();
	}

	/**
	 * get_content_plain function.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	public function get_content_plain() {
		ob_start();
		if ( function_exists( 'woocommerce_get_template') ) {
			woocommerce_get_template( $this->template_plain, array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading()
			),  ' ', WC_EVENT_ORGANIZER_TEMPLATE_DIR );
		}
		return ob_get_clean();
	}


	/**
	 * Initialize Settings Form Fields
	 *
	 * @since 1.0.1
	 */
	public function init_form_fields() {

		// Find currently selected recipient
		$selector = $this->get_option( 'recipient' );
		if ( isset( $_POST['woocommerce_wc_event_organizer_email_lmbk_recipient'] ) ) $selector = $_POST['woocommerce_wc_event_organizer_email_lmbk_recipient']; 
		
		// Set variable for 'Other:' option under recipients
		$other = ( $selector == 'recipient_other' ) ? ' other' : '';

		$this->form_fields = array(
			'enabled'    => array(
				'title'   => 'Enable/Disable',
				'type'    => 'checkbox',
				'label'   => 'Enable this email notification',
				'default' => 'no'
			),			
			'subject'    => array(
				'title'       => 'Email subject',
				'type'        => 'text',
				'description' => 'Defaults to <code>' . $this->subject . '</code>',
				'placeholder' => '',
				'default'     => ''
			),
			'heading'    => array(
				'title'       => 'Email heading',
				'type'        => 'text',
				'description' => 'Defaults to <code>' . $this->heading . '</code>',
				'placeholder' => '',
				'default'     => ''
			),
			'email_type' => array(
				'title'       => 'Email type',
				'type'        => 'select',
				'description' => 'Choose which format of email to send.',
				'default'     => 'html',
				'class'       => 'email_type',
				'options'     => array(
					'plain'	    => __( 'Plain text', 'woocommerce' ),
					'html' 	    => __( 'HTML', 'woocommerce' )
				)
			),
			'recipient' => array(
				'title'       => 'Recipient(s)',
				'type'        => 'select',
				'description' => 'Override event organizer specified per event',
				'default'     => 'site_admin_recipient',
				'class'       => 'email_recipient' . $other,
				'options'     => array(
					'recipient_event_specific'  => __( 'Recipient specified per event', 'woocommerce' ),
					'recipient_new_order'  		=> __( 'Recipient specified for new order', 'woocommerce' ),					
					'recipient_site_admin'		=> __( 'Website Administrator', 'woocommerce' ),
					'recipient_other'		=> __( 'Other:', 'woocommerce' )
				)
			),
			'other_recipient' => array(
				'title'       => '',
				'type'        => 'text',
				'description' => '',
				'placeholder' => 'your@email.com',
				'default'     => ''
			),
			'edit_links'	  => array(
				'title'		  => 'Edit Links',
				'label'       => 'Add event and order edit links in event organizer email',
				'type'        => 'checkbox',
				'default'     => 'yes'
			),
		);
	}

	/**
	 * Adds the option to resend this email from the Edit Order Screen
	 *
	 * @since 1.0.1
	 */
	public function add_resend_event_organizer_action( $emails ) {

		if ( ! $this->is_enabled() )
			return $emails;

		$order = get_the_ID();

		if ( empty( $order ) ) {
			return $emails;
		}

		// Remove these lines to make resending email available when order is in 'Processing' status
		$has_tickets = get_post_meta( $order, '_tribe_has_tickets', true );
		if ( ! $has_tickets ) {
			return $emails;
		}

		$emails[] = $this->id;
		return $emails;
	}


} // end \WC_Event_Organizer_Email class
