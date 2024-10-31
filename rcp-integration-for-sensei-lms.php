<?php
/**
 * Plugin Name:       RCP Integration for Sensei LMS
 * Plugin URI:        https://silicodevalley.com/
 * Description:       Sell online courses with Sensei LMS & Restrict Content Pro
 * Version:           1.0.0
 * Author:            David Perálvarez
 * Author URI:        https://davidperalvarez.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rcp-integration-for-sensei-lms
 * Domain Path:       /languages
 */

/**
 * Execute actions only if plugins Sensei LMS and Restrict Content Pro are enabled
 */

add_action( 'plugins_loaded', 'dp_slmsrcp_execute_plugin' );

function dp_slmsrcp_execute_plugin(){
	if(dp_slmsrcp_is_rcp_active() && dp_slmsrcp_is_senseilms_active() && dp_slmsrcp_valid_rcp_version()):
		add_action( 'plugins_loaded', 'dp_slmsrcp_load_textdomain' );
		add_action( 'rcp_add_subscription_form', 'dp_slmsrcp_add_row' );
		add_action( 'rcp_add_subscription', 'dp_slmsrcp_save_level_meta', 10, 2 );
		add_action( 'rcp_edit_subscription_level', 'dp_slmsrcp_save_level_meta', 10, 2 );
		add_action( 'rcp_edit_subscription_form', 'dp_slmsrcp_edit_row' );
		add_action( 'rcp_transition_membership_status', 'dp_slmsrcp_transition_membership_status', 10, 3 );
	else:
		add_action( 'admin_notices', 'dp_slmsrcp_admin_notice' );
	endif;	
}

/**
 * Delete course start button
 */

add_action( 'wp', 'dp_slmsrcp_delete_course_start_button' );

function dp_slmsrcp_delete_course_start_button(){
	if( is_singular('course') ):
		remove_action( 'sensei_single_course_content_inside_before', array('Sensei_Course','the_course_enrolment_actions'), 30);
	endif;	
}

/**
 * Load text domain
 */

function dp_slmsrcp_load_textdomain(){
  load_plugin_textdomain( 'sensei-lms-restrict-content-pro', false, dirname( plugin_basename(__FILE__)).'/languages' );
}


/**
 * Add courses select field to the "Add New Level" form.
 *
 * https://docs.restrictcontentpro.com/article/2057-rcp-add-subscription-form
 */

function dp_slmsrcp_add_row() {
?>
	<tr class="form-field">
		<th scope="row" valign="top">
			<label for="rcp-role"><?php esc_html_e( 'Courses', 'rcp-integration-for-sensei-lms' ); ?></label>
		</th>
		<td>
			<select name="_sensei_restrict_content_pro_courses[]" multiple="multiple">
				<?php 
				$args = array(
					'post_type' => 'course',
					'post_status' => 'publish',
					'posts_per_page' => -1,
				);

				$courses_query = new WP_Query( $args );
				if( $courses_query->have_posts() ):
					while( $courses_query->have_posts() ):
						$courses_query->the_post();
						?>
						<option value="<?php echo esc_attr( get_the_ID() ); ?>">
							<?php echo esc_attr( get_the_title() ); ?>
						</option>
						<?php
					endwhile; 
				endif;
				wp_reset_postdata();
				?>
			</select>
			<p class="description"><?php esc_html_e( 'Sensei courses you want to associate this membership level with. Hold ctrl on Windows or cmd on Mac to select multiple courses.', 'rcp-integration-for-sensei-lms' ); ?></p>
		</td>
	</tr>
<?php
}


/**
 * Add courses select field to the "Edit Subscription Level" form.
 * https://docs.restrictcontentpro.com/article/2059-rcp-edit-subscription-form
 */

function dp_slmsrcp_edit_row( $level ) {
	$level_obj = new RCP_Levels();
	$saved_courses = maybe_unserialize( $level_obj->get_meta( $level->id, '_sensei_restrict_content_pro_courses', true ) );
?>
	<tr class="form-field">
		<th scope="row" valign="top">
			<label for="rcp-role"><?php esc_html_e( 'Courses', 'rcp-integration-for-sensei-lms' ); ?></label>
		</th>
		<td>
			<select name="_sensei_restrict_content_pro_courses[]" multiple="multiple">
				<?php 
				$args = array(
					'post_type' => 'course',
					'post_status' => 'publish',
					'posts_per_page' => -1,
				);

				$courses_query = new WP_Query( $args );
				if( $courses_query->have_posts() ):
					while( $courses_query->have_posts() ):
						$courses_query->the_post();
						$course_id = get_the_ID();
						?>
						<option value="<?php echo esc_attr( $course_id ); ?>" <?php dp_slmsrcp_selected_course( $course_id, $saved_courses ); ?>>
							<?php echo esc_attr( get_the_title() ); ?>
						</option>
						<?php
					endwhile; 
				endif;
				wp_reset_postdata();
				?>
			</select>
			<p class="description"><?php esc_html_e( 'Sensei courses you want to associate this membership level with. Hold ctrl on Windows or cmd on Mac to select multiple courses.', 'rcp-integration-for-sensei-lms' ); ?></p>
		</td>
	</tr>
<?php
}


/**
 * Save Restrict Content Pro meta (courses IDs)
 * 
 * @param  int    $level_id ID of RCP_Levels
 * @param  array  $product  Restrict Content Pro product information
 *
 * https://docs.restrictcontentpro.com/article/2058-rcp-add-subscription
 */

function dp_slmsrcp_save_level_meta( $level_id, $args ) {
	
	// https://docs.restrictcontentpro.com/article/1704-rcp-levels
	$level = new RCP_Levels();

	$array = $args['_sensei_restrict_content_pro_courses'];
	$new_courses = array_map( function($item){ return intval(sanitize_text_field($item)); }, $array  );

	$level->update_meta( $level_id, '_sensei_restrict_content_pro_courses', $new_courses );
}


/**
 * Update user course access when member is updated
 * 
 * @param  string $old_status Old member status
 * @param  string $new_status New member status
 * @param  int    $membership_id  ID of the membership
 *
 * https://docs.woocommerce.com/sensei-apidocs/source-class-Sensei_Utils.html#1347-1380
 * https://docs.woocommerce.com/sensei-apidocs/source-class-Sensei_Utils.html#772-806
 * https://docs.restrictcontentpro.com/article/2138-rcp-transition-membership-status
 *
 */

function dp_slmsrcp_transition_membership_status( $old_status, $new_status, $membership_id ){
	
	// Get the courses id associated with a membership level
	$membership = rcp_get_membership( $membership_id );
	$customer = $membership->get_customer();
	$user_id  = $customer->get_user_id();
	$level_id = $membership->get_object_id();
	$level = new RCP_Levels();
	$slms_courses = maybe_unserialize( $level->get_meta( $level_id, '_sensei_restrict_content_pro_courses', true ) );

	// If no Sensei LMS course associated, exit
	if( empty( $slms_courses ) )
		return;

	// Get current courses of the user
	$current_courses = get_user_meta( $user_id, 'dp_slmsrcp_current_courses', true );

	if( $current_courses === '' )
		$current_courses = [];

	// User is purchasing a new membership level
	if( 'active' == $new_status || 'free' == $new_status ):

		foreach( $slms_courses as $course_id ):
			// Check if this course have been previously purchased
			if( !in_array($course_id, $current_courses) ):
				// Start the course
				WooThemes_Sensei_Utils::user_start_course( $user_id, $course_id );
			endif;

			// Update current courses of the user
			$current_courses[] = $course_id;
			update_user_meta( $user_id, 'dp_slmsrcp_current_courses', $current_courses );

		endforeach;

	// User is unsuscribing from a membership level
	elseif( 'expired' == $new_status || 'cancelled' == $new_status ):
	// elseif( 'expired' == $new_status ):

		foreach( $slms_courses as $course_id ):		
			// Delete the course id from current courses array
			$index = array_search($course_id, $current_courses);

			if( $index !== false ):
			  unset($current_courses[$index]);
				update_user_meta( $user_id, 'dp_slmsrcp_current_courses', $current_courses );
			endif;

			// Check if there is still ocurrences of this course id
			if( array_search($course_id, $current_courses) === false ):	
				WooThemes_Sensei_Utils::sensei_remove_user_from_course( $course_id, $user_id );	
			endif;
			
		endforeach;
	endif;
}


/**
 * Helper functions
 */

function dp_slmsrcp_selected_course( $course_id, $courses_array )
{
	if( in_array( $course_id, $courses_array ) ):
		echo 'selected="selected"';
	endif;
}

function dp_slmsrcp_is_rcp_active(){
	if( class_exists('RCP_Levels') ):
		return true;
 	endif;
 	return false;
}

function dp_slmsrcp_is_senseilms_active(){
	if( class_exists('Sensei_Utils') ):
		return true;
 	endif;
 	return false;
}

function dp_slmsrcp_valid_rcp_version(){
	$plugin_data = get_plugin_data( plugin_dir_path( __DIR__ ).'/restrict-content-pro/restrict-content-pro.php' );
	return version_compare($plugin_data['Version'],'3.0', '>=');
}

function dp_slmsrcp_admin_notice() {
?>
<div class="notice notice-error is-dismissible">
  <p><?php esc_html_e( 'To be able to run "RCP Integration for Sensei LMS" you must have activated the plugins "Sensei LMS" and "Restrict Content Pro (version 3.0 or higher)".', 'rcp-integration-for-sensei-lms' ); ?></p>
</div>
<?php
}

/**
 * Uninstall plugin
 */

register_uninstall_hook(__FILE__, 'dp_slmsrcp_uninstall_plugin' );

function dp_slmsrcp_uninstall_plugin(){
  $users = get_users();
  foreach ($users as $user) {
    delete_user_meta( $user->ID, 'dp_slmsrcp_current_courses' );
  }
}