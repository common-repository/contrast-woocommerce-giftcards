<?php
/**
	* Plugin Name: Contrast woocommerce giftcards
	* Plugin URI: http://contrast.fi
	* Description: Plugin that creates woocommerce category term 'gift-cards' and creates as many coupons as visitors have bought them
	* Version: 1.0.0
	* Author: Sampo Kataja / Contrast Digital Oy
	* Author Email: hello@contrast.fi test
	* Copyright: (c) 2016 Contrast Digital Ltd. (hello@contrast.fi)
	*
	* License: GNU General Public License v3.0
	* License URI: http://www.gnu.org/licenses/gpl-3.0.html
	*
	* @author    Contrast Digital Ltd.
	* @category
	* @copyright Copyright (c) 2016, Contrast Digital Oy
	* @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

class Cntrst_Giftcards {

	function __construct() {
		global $coupon_ids;
		$coupon_ids = array();

        // if (!session_id()) {
        //     session_start(); // Start the session
        // }

		//Register activation and deactivation hooks
		register_activation_hook( __FILE__, array( &$this, 		'activate_cntrst_giftcards' ) );
		register_deactivation_hook( __FILE__, array( &$this, 	'deactivate_cntrst_giftcards' ) );

		/**
		* Call init only when registeration has been completed
		* We know if it has been completed if there is a term called 'gift-cards'
		* This is because registration creates this term
		*/
		if( term_exists( 'gift-cards', 'product_cat' ) ) {
			add_action( 'load-post.php', 		array( &$this, 	'cntrst_giftcards_post_meta_boxes_setup' ) );
			add_action( 'load-post-new.php', 	array( &$this, 	'cntrst_giftcards_post_meta_boxes_setup' ) );
			add_action( 'init', 				array( &$this, 	'cntrst_init_giftcards' ) );
    		add_action( 'plugins_loaded', 		array( &$this, 	'cntrst_after_loaded' ) );
		}
	}

	/* Meta box setup function */
	function cntrst_giftcards_post_meta_boxes_setup() {
		add_action( 'add_meta_boxes', 	array( &$this, 		'cntrst_giftcards_add_post_meta_boxes' ) );
		add_action( 'save_post', 		array( &$this, 		'cntrst_giftcards_save_post_class_meta'), 10, 2 );
	}

	/* Create metabox function */
	function cntrst_giftcards_add_post_meta_boxes() {
		add_meta_box(
			'_product_coupon_discount',
			esc_html__( 'Coupon discount value', 'cntrst-giftcards' ),
			array(&$this, 'cntrst_giftcards_post_class_meta_box'),
			'product',
			'side',
			'default'
		);
	}
	function cntrst_giftcards_post_class_meta_box( $object, $box ) { ?>
		<?php wp_nonce_field( basename( __FILE__ ), '_product_coupon_discount_nonce' ); ?>
		<p>
			<label for="_product_coupon_discount"><?php _e( 'Use "%" if percent discount coupon, "€" if fixed discount. <br><br>
			Example1: 25%,<br>
			Example2: 30€ ', 'cntrst-giftcards' ); ?></label>
			<br />
			<input class="widefat" type="text" name="_product_coupon_discount" id="_product_coupon_discount" value="<?php echo get_post_meta( $object->ID, '_product_coupon_discount', true ); ?>" size="30" />
		</p><?php
	}

	/* Save the meta box's post metadata */
	function cntrst_giftcards_save_post_class_meta( $post_id, $post ) {
		/* Verify the nonce before proceeding. */
		if ( !isset( $_POST['_product_coupon_discount_nonce'] ) || !wp_verify_nonce( $_POST['_product_coupon_discount_nonce'], basename( __FILE__ ) ) )
		return $post_id;

		/* Get the post type object */
		$post_type = get_post_type_object( $post->post_type );

		/* Check if the current user has permission to edit the post. */
		if ( !current_user_can( $post_type->cap->edit_post, $post_id ) ) {
			return $post_id;
		}

		$new_meta_value = ( isset( $_POST['_product_coupon_discount'] ) ? esc_attr( $_POST['_product_coupon_discount'] ) : '' );
		$meta_key 		= '_product_coupon_discount';
		$meta_value 	= get_post_meta( $post_id, $meta_key, true );

		if ( $new_meta_value && '' == $meta_value ) {
			add_post_meta( $post_id, $meta_key, $new_meta_value, true );
		} elseif ( $new_meta_value && $new_meta_value != $meta_value ) {
			update_post_meta( $post_id, $meta_key, $new_meta_value );
		} elseif ( '' == $new_meta_value && $meta_value ) {
			delete_post_meta( $post_id, $meta_key, $meta_value );
		}
	}

	/**
	* When plugin is activated, let's create a term for product category called 'gift-cards'
	* After the term is created, add its' ID to options table for later use
	*/
	function activate_cntrst_giftcards() {
		if( !term_exists( 'gift-cards', 'product_cat' ) ) {
			wp_insert_term(
			  __( 'Giftcards', 'cntrst-giftcards' ), // the term
			  'product_cat', // the taxonomy
			  array(
			    'slug' => 'gift-cards',
			  )
			);
		}

		if( term_exists( 'gift-cards', 'product_cat' ) ) {
			$gift_cards_term = get_term_by( 'slug', 'gift-cards', 'product_cat' );
			add_option( 'gift-cards-id', $gift_cards_term->term_id );
		}
	}

	/**
	* When plugin is deactivated, delete the term 'gift-cards' and its' ID from options table
	*/
	function deactivate_cntrst_giftcards() {
		if( term_exists( 'gift-cards', 'product_cat' ) ) {
			$term_to_be_deleted = get_option( 'gift-cards-id' );
			wp_delete_term( $term_to_be_deleted, 'product_cat' );
			delete_option( 'gift-cards-id' );
		}
	}

	/**
	* Init
	* Activate woocommerce hooks
	*/
	function cntrst_init_giftcards() {
		if ( class_exists( 'Woocommerce' ) ) {
			add_action( 'woocommerce_checkout_update_order_meta', 	array( &$this,    	'cntrst_giftcards_check_if_coupon_will_be_inserted' ) );
			add_action( 'woocommerce_email_order_meta', 			array( &$this, 		'cntrst_email_couponcode' ), 10, 3 );
			add_action( 'woocommerce_thankyou', 					array( &$this,      'cntrst_giftcards_update_coupon_description' ) );
			add_action( 'woocommerce_view_order', 					array( &$this, 		'cntrst_view_order_giftcards' ), 1 );
		}
	}

	function cntrst_after_loaded() {
  	  load_plugin_textdomain('cntrst-giftcards', false, dirname( plugin_basename( __FILE__ ) ) . '/i18n/languages');
    }

	/**
	* Check to see if user has coupon product in cart
	* @return array consisting all the needed data to continue
	* --> description, quantity
	*/
	function cntrst_check_product_in_cart() {
	    global $woocommerce;
	    $product_in_cart = array();
	    $gift_card_id = get_option( 'gift-cards-id' );

	    foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $values ) { // start of the loop that fetches the cart items
	        $product 	= $values['data'];
	        $terms      = get_the_terms( $product->id, 'product_cat' );
	        $quantity   = $values['quantity']; //quantity from the cart

	        // second level loop search, in case some items have several categories, we are searching for coupon term
	        foreach ( $terms as $term ) {
	            $_categoryid = $term->term_id;
	            if ( ( $_categoryid === (int)$gift_card_id ) ) { //coupon category is in cart!
	            	 $product_amount_and_type_multiarr = array(); //Add quantity, description (€/%) per product to this array

			        if( $quantity !== 1 || $quantity !== 0 ) { //If quantity more than one
			            foreach( $product as $product_data ) { //Iterate trought every product in cart
			                $product_amount_and_type = get_post_meta( $product_data, '_product_coupon_discount', true ); //Get the metavalue for product
			                if( $product_amount_and_type ) {
			                    $product_amount_and_type_multiarr[] = $product_amount_and_type; //Add it to the array declared earlier
			                }
			            }
			        }

	                $product_in_cart[] = array(
	                    'product' => array(
	                        'desc'      => $product_amount_and_type_multiarr,
	                        'quantity'  => $quantity,
	                    ),
	                );

	            }
	        }
	    }

	    return $product_in_cart;
	}

	/**
	* Handler function for data returned from check_product_in_cart function
	* Will call a function to create coupons, after counting how many
	* and which type of coupons will be inserted
	*/
	function giftcards_check_if_coupon_will_be_inserted() {
	    $stored_data                = self::cntrst_init_giftcards();
	    $description_arr            = array();
	    $quantity_arr               = array();

	    foreach( $stored_data as $data ) {
	        if( $data['product']['desc'] ) {
	            $description_arr[] 	= $data['product']['desc'];
	        }

	         if( $data['product']['quantity'] ) {
	            $quantity_arr[]	 	= $data['product']['quantity'];
	        }
	    }

	    //Loop trough quantity and description and call create_coupon function as many times as needed
	    $k = 0;
	    foreach( $quantity_arr as $q ) {
	        for( $i = 0; $i < $q; $i++ ) {
	            self::create_coupon( $description_arr[$k] );
	        }
	        $k++;
	    }
	}

	/**
	* Function that creates coupons
	*/
	function create_coupon( $description_arr ) {
	    foreach( $description_arr as $description ) {
	        if ( strpos( $description, '%' ) !== false ) { // IF "%" is found
	            $discount_type  = 'percent_product'; // Discount type as percent discount
	            $amount         = $description; // Let's use percentage
	        } else { // If description is €
	            $discount_type  = 'fixed_cart'; // Discount type is fixed
	            $amount         = str_replace( '%','', $description ); // And amount is without "%" sign
	        }

	        $args = array(
	            'posts_per_page'   => -1,
	            'orderby'          => 'title',
	            'order'            => 'asc',
	            'post_type'        => 'shop_coupon',
	            'post_status'      => 'publish',
	        );

	        $coupons 		= get_posts( $args ); // Get all coupons so we can check the names later
	        $coupon_names 	= array(); // We're going to push the coupon names into this array
	        $coupon_name;

	        foreach( $coupons as $coupon ) {
	            array_push( $coupon_names, $coupon->post_title ); // Pushing here
	        }

	        $six_rndm_digits = self::cntrst_generate_coupon_code(); // Generate coupon code

	        if( !in_array( $six_rndm_digits, $coupon_names ) ) { // Check if not used already
	            $coupon_name = $six_rndm_digits;
	        } else {
	            $coupon_name = self::cntrst_generate_coupon_code(); // If already in use, generate new one
	        }

	        $coupon_code 	= $coupon_name;
	        $amount 		= $amount; // Amount
	        $discount_type 	= $discount_type; // Type: fixed_cart, percent, fixed_product, percent_product

	        $coupon = array(
	            'post_title'    => $coupon_code,
	            'post_content'  => '',
	            'post_status'   => 'publish',
	            'post_author'   => 1,
	            'post_type'     => 'shop_coupon'
	        );

	        $new_coupon_id = wp_insert_post( $coupon ); //creates the coupon

	        global $coupon_ids;
	        array_push( $coupon_ids, $new_coupon_id ); //Add ids of coupons in cart to array...

	        $transient_data = $coupon_ids; //Transient data to be set in transient
	        $key = wp_generate_password();
	        setcookie('coupon_ids_', $key, time() + 30 * 60 ); //unique cookie ident
	        set_transient( 'coupon_'.$key, $transient_data, 30 * 60 );

	        // Add meta
	        update_post_meta( $new_coupon_id, 'discount_type', $discount_type );
	        update_post_meta( $new_coupon_id, 'coupon_amount', $amount );
	        update_post_meta( $new_coupon_id, 'individual_use', 'no' );
	        update_post_meta( $new_coupon_id, 'product_ids', '' );
	        update_post_meta( $new_coupon_id, 'exclude_product_ids', '' );
	        update_post_meta( $new_coupon_id, 'usage_limit', '1' );
	        update_post_meta( $new_coupon_id, 'expiry_date', '' );
	        update_post_meta( $new_coupon_id, 'apply_before_tax', 'yes' );
	        update_post_meta( $new_coupon_id, 'free_shipping', 'no' );
	    }
	}

	/**
	* Function to generate 6 random digits
	* @return 6 random digits as a string
	*/
	function cntrst_generate_coupon_code() {
	    $six_rndm_digits = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

	    return $six_rndm_digits;
	}

	function cntrst_giftcards_update_coupon_description( $order_id ) {
	    global $woocommerce;

        $ids = get_transient( 'coupon_'.$_COOKIE['coupon_ids_'] ); //Get stuff from transient

        if( $ids ) {
	        update_post_meta( $order_id, 'coupon_ids', $ids );

	        /*
	        * We want this update to happen because if there are many coupon orders, customer might want to have some sort of link between coupons and orders
	        * Otherwise it would be a bit confusing to keep track
	        * So we add some information to the just ordered coupons to inform customers
	        */
	        foreach( $ids as $coupon_id ) {
	            $coupon_to_be_updated = array( //Update just ordered coupons excerpt field by adding order id to it
	                'ID'            => $coupon_id,
	                'post_excerpt'  => __('This coupon is part of the order number: '.$order_id, 'cntrst-giftcards'),
	            );
	            wp_update_post( $coupon_to_be_updated );
	        }
   		 	delete_transient( 'coupon_'.$_COOKIE['coupon_ids_'] ); // Unset session in the end
    	}
	}


	function cntst_view_order_giftcards( $order_id ) {
		$ids = get_post_meta( $order_id, 'coupon_ids', true );
		if( $ids ) {
			echo '<div>';
			echo '<h3>'.__('Coupon codes', 'cntrst-giftcards').'</h3>';
			foreach( $ids as $coupon_id ) {
				$coupon_code = get_the_title( $coupon_id );
				echo '<p>'.__('Coupon code:', 'cntrst-giftcards').' '.$coupon_code.'</p>';
			}
			echo '</div>';
		}
  	}

	function cntrst_email_couponcode( $order, $sent_to_admin, $plain_text ) {
		$ids 			= get_post_meta( $order, 'coupon_ids', true );
		$coupon_code 	= get_the_title( $coupon_id );

		if ( $plain_text ) { //Do this if we have a plain email
	     	foreach( $ids as $coupon_id ) {
	            echo "\n".__('Coupon code:', 'cntrst-giftcards').' '.$coupon_code."\n";
	        }
		} else {
			//Do this if we have a normal email
			echo '<div>';
			echo '<h3>'.__('Your coupon code(s)', 'cntrst-giftcards').'</h3>';
			foreach( $ids as $coupon_id ) {
				echo '<p>'.__('Coupon code:', 'cntrst-giftcards').' '.$coupon_code.'</p>';
			}
			echo '</div>';
		}
	}
}

new Cntrst_Giftcards();
