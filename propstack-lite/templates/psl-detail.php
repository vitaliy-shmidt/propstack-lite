<?php
defined('ABSPATH') || exit;
$item = get_query_var('psl_item') ?: [];
get_header();
?>
<main class="psl-detail">
	<div class="fusion-row">
		<section class="full-width">
			<div class="page type-page status-publish hentry">
				<div class="post-content">
					<div class="fusion-fullwidth fullwidth-box fusion-builder-row-1 fusion-flex-container has-pattern-background has-mask-background nonhundred-percent-fullwidth non-hundred-percent-height-scrolling">
					<h1 class="title-heading-center"><?php echo $item['title']['value'] ?></h1>
					</div>
				</div>
			
			</div>
		</section>
	
	</div>
  <pre>
  <?php print_r($item); ?>
  </pre>

</main>
<?php get_footer(); ?>
