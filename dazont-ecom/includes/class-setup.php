<?php
defined( 'ABSPATH' ) || exit;

/**
 * "This needs setting up first" — said where the work happens.
 *
 * The settings of this plugin are wide, and a function that quietly does
 * nothing because one box was never filled is a function nobody knows exists.
 * A module that is not ready must SAY SO on the screen where somebody is
 * trying to use it, name what is missing in the owner's words, and hand him
 * the link that opens the exact field — never leave him to find out.
 *
 * One renderer, so every module says it the same way and the owner learns to
 * recognise it at a glance.
 */
final class DZE_Setup {

	/**
	 * A checklist of what a module still needs.
	 *
	 * @param string $title What is not ready, in one short phrase.
	 * @param array<int,array{label:string,url?:string,done?:bool,note?:string}> $items
	 */
	public static function render( string $title, array $items ): void {
		$left = 0;
		foreach ( $items as $item ) {
			$left += empty( $item['done'] ) ? 1 : 0;
		}
		if ( ! $left ) {
			return;
		}
		?>
		<div class="dze-setup">
			<p class="dze-setup__t"><?php echo esc_html( $title ); ?></p>
			<ul class="dze-setup__l">
				<?php foreach ( $items as $item ) :
					$done = ! empty( $item['done'] ); ?>
					<li class="<?php echo $done ? 'is-done' : ''; ?>">
						<span class="dze-setup__m"><?php echo $done ? '&#10003;' : '&#9679;'; ?></span>
						<?php if ( ! $done && ! empty( $item['url'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $item['url'] ); ?>"><?php echo esc_html( (string) $item['label'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (string) $item['label'] ); ?>
						<?php endif; ?>
						<?php if ( ! empty( $item['note'] ) ) : ?>
							<span class="dze-setup__n"><?php echo esc_html( (string) $item['note'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<style>
			.dze-setup{max-width:880px;margin:10px 0 18px;padding:12px 16px;background:#fcf9e8;border-left:4px solid #dba617;}
			.dze-setup__t{margin:0 0 6px;font-weight:600;}
			.dze-setup__l{margin:0;list-style:none;}
			.dze-setup__l li{margin:2px 0;}
			.dze-setup__l li.is-done{color:#646970;}
			.dze-setup__m{display:inline-block;width:16px;color:#dba617;}
			.dze-setup__l li.is-done .dze-setup__m{color:#0a7040;}
			.dze-setup__n{color:#646970;font-size:12px;margin-left:6px;}
		</style>
		<?php
	}
}
