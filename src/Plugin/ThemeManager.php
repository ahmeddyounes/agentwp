<?php
/**
 * Theme manager.
 *
 * @package AgentWP\Plugin
 */

namespace AgentWP\Plugin;

/**
 * Manages user theme preferences.
 */
final class ThemeManager {

	/**
	 * User meta key for theme preference.
	 */
	public const THEME_META_KEY = 'agentwp_theme_preference';

	/**
	 * Valid theme values.
	 */
	public const VALID_THEMES = array( 'light', 'dark' );

	/**
	 * Get the current user's theme preference.
	 *
	 * @param int|null $userId User ID (defaults to current user).
	 * @return string Theme preference ('light', 'dark', or empty).
	 */
	public function getUserTheme( ?int $userId = null ): string {
		$userId = $userId ?? get_current_user_id();

		if ( $userId <= 0 ) {
			return '';
		}

		$theme = get_user_meta( $userId, self::THEME_META_KEY, true );

		return in_array( $theme, self::VALID_THEMES, true ) ? $theme : '';
	}

	/**
	 * Set the current user's theme preference.
	 *
	 * @param string   $theme  Theme to set ('light' or 'dark').
	 * @param int|null $userId User ID (defaults to current user).
	 * @return bool True on success, false on failure.
	 */
	public function setUserTheme( string $theme, ?int $userId = null ): bool {
		$userId = $userId ?? get_current_user_id();

		if ( $userId <= 0 ) {
			return false;
		}

		if ( ! in_array( $theme, self::VALID_THEMES, true ) ) {
			return false;
		}

		return (bool) update_user_meta( $userId, self::THEME_META_KEY, $theme );
	}

	/**
	 * Clear the current user's theme preference.
	 *
	 * @param int|null $userId User ID (defaults to current user).
	 * @return bool True on success, false on failure.
	 */
	public function clearUserTheme( ?int $userId = null ): bool {
		$userId = $userId ?? get_current_user_id();

		if ( $userId <= 0 ) {
			return false;
		}

		return (bool) delete_user_meta( $userId, self::THEME_META_KEY );
	}

	/**
	 * Generate inline script for initial theme setup.
	 *
	 * This prevents flash of wrong theme by setting the theme
	 * before the DOM is fully loaded.
	 *
	 * @param string $savedTheme The saved theme preference.
	 * @return string JavaScript code for inline script tag.
	 */
	public function getInitialThemeScript( string $savedTheme ): string {
		$script = '(function(){';
		// Use JSON_HEX_TAG to prevent XSS via </script> in JSON strings.
		$script .= 'var theme=' . wp_json_encode( $savedTheme, JSON_HEX_TAG | JSON_HEX_AMP ) . ';';
		$script .= "if(!theme){try{theme=window.localStorage.getItem('agentwp-theme-preference');}catch(e){theme='';}}";
		$script .= "if(theme!=='light'&&theme!=='dark'){return;}";
		$script .= 'var root=document.documentElement;';
		$script .= 'root.dataset.theme=theme;';
		$script .= 'root.style.colorScheme=theme;';
		$script .= '})();';

		return $script;
	}

	/**
	 * Output the theme initialization script in admin head.
	 *
	 * @return void
	 */
	public function outputThemeScript(): void {
		$theme  = $this->getUserTheme();
		$script = $this->getInitialThemeScript( $theme );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script>' . $script . '</script>';
	}

	/**
	 * Check if a theme value is valid.
	 *
	 * @param string $theme Theme to check.
	 * @return bool
	 */
	public function isValidTheme( string $theme ): bool {
		return in_array( $theme, self::VALID_THEMES, true );
	}
}
