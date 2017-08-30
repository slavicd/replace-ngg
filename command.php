<?php
/**
 * Replaces singlepic shortcodes with simple links, while making sure to convert
 * the relevant ngg pictures into native WP posts.
 *
 * This command should be run with wp-cli, against production data.
 *
 * User: slavic
 * Date: 8/28/17
 * Time: 2:14 PM
 */

class WpCli_Command_ReplaceNgg
{
	const POST_BATCH_SIZE = 1000;   // load this many wp posts once

	private $posts;                 // post buffer
	private $reached_end = false;   // no more posts to fetch
	private $posts_offset = 0;
	private $shortcodes = [];       // assoc array of ngg picture paths and more


	/**
	 * Replaces NextGen Gallery singlepic shortcodes with native Wordpress pictures.
	 *
	 * ## OPTIONS
	 *
	 * [--limit-posts=<value>]
	 * : limits the number of posts to process (debug purposes)
	 * ---
	 * default: 0
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp custom replace-ngg --limit-posts=20
	 *
	 * @when after_wp_load
	 */
	public function __invoke($args, $assocArgs) {
		$shortcode = 'singlepic';

		$postCounter = 0;

		while ($post = $this->fetchPost($shortcode)) {
			WP_CLI::log("Post " . $post->ID . '.');

			// find all occurences of the shortcode
			$matchRes = preg_match_all('/\[singlepic=(?P<picid>\d+).*\]/', $post->post_content, $matches);
			if ($matchRes===false) {
				WP_CLI::error('Error in regex.');
			}

			if ($matchRes) {
				foreach ($matches['picid'] as $picId) {
					//WP_CLI::log("\t" . 'Found singlepic shortcode ' . $picId . ' in post ' . $post->ID);

					if (!array_key_exists($picId, $this->shortcodes)) {
						$this->shortcodes[$picId] = $this->expandShortcode($shortcode, $picId);
					}

					WP_CLI::log("\tShortcode {$picId}: " . $this->shortcodes[$picId]->path);
				}
			}

			WP_CLI::log('Post ' . $post->ID . ': ' . $matchRes . ' matches.');

			++$postCounter;

			if ($assocArgs['limit-posts'] > 0 && $postCounter == $assocArgs['limit-posts']) {
				WP_CLI::success('Reached limit-posts threshold. Halting.');
				break;
			}
		}

		WP_CLI::success("Done.");
	}

	/**
	 * Returns posts containing $shortcode references. Call again to get more until no more exist.
	 *
	 * @param string $shortcode
	 * @return array
	 */
	private function fetchPost($shortcode='singlepic')
	{
		if (is_null($this->posts) || (!$this->reached_end && !current($this->posts))) {
			global $wpdb;

			$limit = self::POST_BATCH_SIZE;
			$this->posts_offset += $limit;

			$this->posts = $wpdb->get_results(
				"SELECT * FROM {$wpdb->prefix}posts WHERE post_type IN ('post', 'page')" .
				"    AND post_content LIKE '%[{$shortcode}%]%' ORDER BY ID " .
				"LIMIT {$this->posts_offset}, {$limit}",
				OBJECT
			);

			WP_CLI::log('Fetched ' . sizeof($this->posts) . " posts with shortcodes.");
		}

		if (empty($this->posts)) {
			$this->reached_end = true;
			return false;
		}

		$cur = current($this->posts);
		next($this->posts);
		return $cur;
	}

	/**
	 * @param string $code future: what shortcode to parse
	 * @param mixed $data shortcode data
	 *
	 * @return stdClass
	 */
	private function expandShortcode($code, $data)
	{
		switch ($code) {
			case 'singlepic':
				return $this->expandSinglepic($data);
			default: WP_CLI::error($code . ' shortcode is not supported!');
		}
	}

	/**
	 * Returns an object representing a "parsed" shortcode with following fields: id, file_path
	 * @param int $picId
	 * @return stdClass
	 */
	private function expandSinglepic($picId)
	{
		global $wpdb;

		$picData = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}ngg_pictures WHERE pid={$picId}",
			OBJECT
		);

		$galData = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}ngg_gallery WHERE gid={$picData->galleryid}",
			OBJECT
		);

		return (object) [
			'id' => $picId,
			'path' => $galData->path . '/' . $picData->filename
		];
	}
}

WP_CLI::add_command('custom replace-ngg', WpCli_Command_ReplaceNgg::class);

