<?php

class WP_Slack_Event_Manager {

	/**
	 * @var WP_Slack_Plugin
	 */
	private $plugin;

	public function __construct( WP_Slack_Plugin $plugin ) {
		$this->plugin = $plugin;

		$this->dispatch_events();
	}

	private function dispatch_events() {

		$events = $this->get_events();

		// Get all integration settings.
		// @todo Adds get_posts method into post type
		// that caches the results.
		$integrations = get_posts( array(
			'post_type'      => $this->plugin->post_type->name,
			'nopaging'       => true,
			'posts_per_page' => -1,
		) );

		foreach ( $integrations as $integration ) {
			$setting = get_post_meta( $integration->ID, 'slack_integration_setting', true );

			// Skip if inactive.
			if ( empty( $setting['active'] ) ) {
				continue;
			}
			if ( ! $setting['active'] ) {
				continue;
			}

			if ( empty( $setting['events'] ) ) {
				continue;
			}

			// For each checked event calls the callback, that's,
			// hooking into event's action-name to let notifier
			// deliver notification based on current integration
			// setting.
			foreach ( $setting['events'] as $event => $is_enabled ) {
				if ( ! empty( $events[ $event ] ) && $is_enabled ) {
					$this->notifiy_via_action( $events[ $event ], $setting );
				}
			}

		}
	}

	/**
	 * Get list of events. There's filter `slack_get_events`
	 * to extend available events that can be notified to
	 * Slack.
	 */
	public function get_events() {
		return apply_filters( 'slack_get_events', array(
			'post_published' => array(
				'action'      => 'transition_post_status',
				'description' => __( 'When a post is published in category', 'slack' ),
				'default'     => true,
				'message'     => function( $category_slug, $new_status, $old_status, $post ) {
					$notified_post_types = apply_filters( 'slack_event_transition_post_status_post_types', array(
						'post',
					) );

					if ( ! in_array( $post->post_type, $notified_post_types ) ) {
						return false;
					}

					$categories = get_the_category( $post->ID );
					$news_feed_category_slug = $category_slug;
					$post_is_in_news_feed = false;

					foreach ($categories as $category ) {
						if ( $category->slug === $news_feed_category_slug ) {
							$post_is_in_news_feed = true;
							break;
						}
					}

					if ( ! $post_is_in_news_feed ) {
						return false;
					}

					if ( 'publish' !== $old_status && 'publish' === $new_status ) {
						$excerpt = has_excerpt( $post->ID ) ?
							apply_filters( 'get_the_excerpt', $post->post_excerpt )
							:
							wp_trim_words( strip_shortcodes( $post->post_content ), 55, '&hellip;' );

						$tags = get_the_tags( $post->ID );
						$tag_names = [];

						foreach ($tags as $tag ) {
							$tag_names[] = $tag->name;
						}

						return sprintf(
							'New post published: *<%1$s|%2$s>* by *%3$s*' . "\n" .
							'> %4$s' . "\n" .
							'Tags: %5$s',

							get_permalink( $post->ID ),
							get_the_title( $post->ID ),
							get_the_author_meta( 'display_name', $post->post_author ),
							$excerpt,
							implode( ' ', $tag_names )
						);
					}
				},
			),
		) );
	}

	public function notifiy_via_action( array $event, array $setting ) {
		$notifier = $this->plugin->notifier;

		$priority = 10;
		if ( ! empty( $event['priority'] ) ) {
			$priority = intval( $event['priority'] );
		}

		$callback = function() use( $event, $setting, $notifier ) {
			$message_args = func_get_args();
			array_unshift($message_args, $setting['category']);
			$message = '';
			if ( is_string( $event['message'] ) ) {
				$message = $event['message'];
			} else if ( is_callable( $event['message'] ) ) {
				$message = call_user_func_array( $event['message'], $message_args );
			}

			if ( ! empty( $message ) ) {
				$setting = wp_parse_args(
					array(
						'text' => $message,
					),
					$setting
				);

				$notifier->notify( new WP_Slack_Event_Payload( $setting ) );
			}
		};
		add_action( $event['action'], $callback, $priority, 5 );
	}
}
