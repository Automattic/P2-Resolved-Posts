<?php
/**
 * P2 Resolved Posts commands for the WP-CLI framework
 *
 * @package wp-cli
 * @since 0.2
 * @see https://github.com/wp-cli/wp-cli
 */
WP_CLI::addCommand( 'p2-resolved-posts', 'P2ResolvedPosts_Command' );
class P2ResolvedPosts_Command extends WP_CLI_Command {

	/**
	 * Help function for this command
	 */
	public static function help() {

		WP_CLI::line( <<<EOB
usage: wp p2-resolved-posts <parameters>
Possible subcommands:
					change_state            Change the state for a given post
					--post_id=Post ID to affect
					--state=State to change the post to 'resolved', 'unresolved', 'normal'
EOB
		);
	}

	/**
	 * Subcommand to change the state for a given post
	 */
	public function change_state( $args, $assoc_args ) {
		global $p2_resolved_posts;

		$defaults = array(
				'post_id' => '',
				'state' => '',
			);
		$this->args = wp_parse_args( $assoc_args, $defaults );

		if ( !get_post( $this->args['post_id'] ) )
			WP_CLI::error( "Please specify a valid post_id" );

		if ( !in_array( $this->args['state'], $p2_resolved_posts->get_state_slugs() ) )
			WP_CLI::error( "Please specify a valid state: " );

		wp_set_post_terms( $this->args['post_id'], $this->args['state'], P2_Resolved_Posts::taxonomy );
		clean_post_cache( $this->args['post_id'] );
		WP_CLI::success( "Changed state for post #{$this->args['post_id']}: {$this->args['state']}" );
	}
	
}