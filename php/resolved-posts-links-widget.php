<?php
/**
 * class P2_Resolved_Posts_Widget
 * Add a widget to the sidebar for filtering posts by resolution
 */
class P2_Resolved_Posts_Widget extends WP_Widget {

	function __construct() {
		$widget_ops = array(
			'classname' => 'p2-resolved-posts',
			'description' => __( 'Allows querying of resolved and unresolved posts', 'p2-resolved-posts' )
		);
		parent::__construct( 'p2_resolved_posts', __( 'P2 Filter Posts' ), $widget_ops);
	}


	function widget( $args, $instance ) {
		extract( $args );
		$title = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );

		echo $before_widget;
		if ( $title )
			echo $before_title . $title . $after_title;

?>
<ul>
<?php if ( is_home() && isset( $_GET['resolved'] ) && $_GET['resolved'] == 'unresolved' ) : ?>
	<li><strong>&bull; <?php _e( 'Show <strong>unresolved</strong> threads', 'p2-resolved-posts' ); ?></strong></li>
<?php else : ?>
	<li><a href="<?php echo esc_url( add_query_arg( 'resolved', 'unresolved', home_url() ) ); ?>"><?php _e( 'Show <strong>unresolved</strong> threads', 'p2-resolved-posts' ); ?></a></li>
<?php endif; ?>

<?php if ( is_tax( P2_Resolved_Posts::taxonomy, 'resolved' ) ) : ?>
	<li><strong>&bull; <?php _e( 'Show resolved threads', 'p2-resolved-posts' ); ?></strong></li>
<?php else : ?>
	<li><a href="<?php echo esc_url( add_query_arg( 'resolved', 'resolved', home_url() ) ); ?>"><?php _e( 'Show resolved threads', 'p2-resolved-posts' ); ?></a></li>
<?php endif; ?>

<?php if ( is_home() && ! isset( $_GET['resolved'] ) ) : ?>
	<li><strong>&bull; <?php _e( 'Show all threads', 'p2-resolved-posts' ); ?></strong></li>
<?php else : ?>
	<li><a href="<?php echo esc_url( home_url() ); ?>"><?php _e( 'Show all threads', 'p2-resolved-posts' ); ?></a></li>
<?php endif; ?>
</ul>

<?php

		echo $after_widget;
	}

	function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, array( 'title' => '') );
		$title = $instance['title'];
?>
		<p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" /></label></p>
<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$new_instance = wp_parse_args((array) $new_instance, array( 'title' => ''));
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		return $instance;
	}

}