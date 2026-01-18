/**
 * @deprecated since 0.2.0 - This legacy wp-element UI bundle is deprecated.
 * The React-based UI (react/src/) is now the supported runtime.
 * This file will be removed in version 1.0.0.
 *
 * Migration: Build the React UI with `npm run build` in the react/ directory.
 * The Vite build output in assets/build/ takes precedence over this legacy bundle.
 */
(function () {
	'use strict';

	// Deprecation warning logged once per session.
	if (!window._agentwpLegacyDeprecationWarned) {
		window._agentwpLegacyDeprecationWarned = true;
		console.warn(
			'[AgentWP] DEPRECATED: The legacy wp-element UI (agentwp-admin.js) is deprecated and will be removed in version 1.0.0. ' +
			'Please build the React UI from the react/ directory. See docs/CHANGELOG.md for migration details.'
		);
	}

	if (!window.wp || !wp.element || !wp.components || !wp.apiFetch) {
		return;
	}

	var apiFetch = wp.apiFetch;
	var config = window.agentwpSettings || {};

	if (config.nonce && apiFetch.createNonceMiddleware) {
		apiFetch.use(apiFetch.createNonceMiddleware(config.nonce));
	}

	if (config.root && apiFetch.createRootURLMiddleware) {
		apiFetch.use(apiFetch.createRootURLMiddleware(config.root));
	}

	var el = wp.element.createElement;
	var render = wp.element.render;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;

	var Button = wp.components.Button;
	var Card = wp.components.Card;
	var CardBody = wp.components.CardBody;
	var CardHeader = wp.components.CardHeader;
	var Flex = wp.components.Flex;
	var FlexItem = wp.components.FlexItem;
	var FormFileUpload = wp.components.FormFileUpload;
	var Notice = wp.components.Notice;
	var SelectControl = wp.components.SelectControl;
	var Spinner = wp.components.Spinner;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;

	var DEFAULT_SETTINGS = {
		model: 'gpt-4o-mini',
		budget_limit: 0,
		hotkey: 'Cmd+K / Ctrl+K',
		theme: 'light',
	};

	var DEFAULT_USAGE = {
		period: 'month',
		total_tokens: 0,
		total_cost_usd: 0,
		breakdown_by_intent: [],
		daily_trend: [],
		period_start: '',
		period_end: '',
	};

	function normalizeSettings(settings) {
		var merged = Object.assign({}, DEFAULT_SETTINGS, settings || {});
		if (merged.theme !== 'dark' && merged.theme !== 'light') {
			merged.theme = DEFAULT_SETTINGS.theme;
		}
		if (!merged.hotkey) {
			merged.hotkey = DEFAULT_SETTINGS.hotkey;
		}
		if (merged.model !== 'gpt-4o' && merged.model !== 'gpt-4o-mini') {
			merged.model = DEFAULT_SETTINGS.model;
		}
		merged.budget_limit = parseFloat(merged.budget_limit);
		if (!Number.isFinite(merged.budget_limit) || merged.budget_limit < 0) {
			merged.budget_limit = DEFAULT_SETTINGS.budget_limit;
		}
		return merged;
	}

	function normalizeUsage(usage) {
		var parsed = Object.assign({}, DEFAULT_USAGE, usage || {});
		parsed.total_tokens = parseInt(parsed.total_tokens, 10) || 0;
		parsed.total_cost_usd = parseFloat(parsed.total_cost_usd) || 0;
		parsed.breakdown_by_intent = Array.isArray(parsed.breakdown_by_intent)
			? parsed.breakdown_by_intent
			: [];
		parsed.daily_trend = Array.isArray(parsed.daily_trend) ? parsed.daily_trend : [];
		parsed.period_start = parsed.period_start || '';
		parsed.period_end = parsed.period_end || '';
		return parsed;
	}

	function formatCurrency(value) {
		if (window.Intl && Intl.NumberFormat) {
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: 'USD',
				maximumFractionDigits: 2,
			}).format(value || 0);
		}

		return '$' + (value || 0).toFixed(2);
	}

	function formatNumber(value) {
		var parsed = typeof value === 'number' ? value : parseFloat(value);
		if (!Number.isFinite(parsed)) {
			return '0';
		}
		if (window.Intl && Intl.NumberFormat) {
			return new Intl.NumberFormat().format(parsed);
		}
		return String(parsed);
	}

	function formatDate(value) {
		if (!value) {
			return __('Not synced yet', 'agentwp');
		}

		var date = new Date(value);
		if (Number.isNaN(date.getTime())) {
			return value;
		}

		return date.toLocaleString();
	}

	function getErrorMessage(error, fallback) {
		if (error && error.message) {
			return error.message;
		}

		if (error && error.data && error.data.message) {
			return error.data.message;
		}

		if (error && error.data && error.data.error && error.data.error.message) {
			return error.data.error.message;
		}

		return fallback;
	}

	function SettingsApp() {
		var _useState = useState(DEFAULT_SETTINGS);
		var settings = _useState[0];
		var setSettings = _useState[1];

		var _useState2 = useState(DEFAULT_SETTINGS);
		var savedSettings = _useState2[0];
		var setSavedSettings = _useState2[1];

		var _useState3 = useState('');
		var apiKeyInput = _useState3[0];
		var setApiKeyInput = _useState3[1];

		var _useState4 = useState('');
		var apiKeyLast4 = _useState4[0];
		var setApiKeyLast4 = _useState4[1];

		var _useState5 = useState(false);
		var hasApiKey = _useState5[0];
		var setHasApiKey = _useState5[1];

		var _useState6 = useState(DEFAULT_USAGE);
		var usage = _useState6[0];
		var setUsage = _useState6[1];

		var _useState7 = useState(true);
		var loading = _useState7[0];
		var setLoading = _useState7[1];

		var _useState8 = useState(false);
		var saving = _useState8[0];
		var setSaving = _useState8[1];

		var _useState9 = useState(null);
		var notice = _useState9[0];
		var setNotice = _useState9[1];

		var _useState10 = useState(null);
		var validationNotice = _useState10[0];
		var setValidationNotice = _useState10[1];

		var _useState11 = useState(false);
		var apiKeyBusy = _useState11[0];
		var setApiKeyBusy = _useState11[1];

		var _useState12 = useState(false);
		var usageLoading = _useState12[0];
		var setUsageLoading = _useState12[1];

		var _useState13 = useState(null);
		var usageBaseline = _useState13[0];
		var setUsageBaseline = _useState13[1];

		useEffect(function () {
			var isMounted = true;

			async function fetchSettings() {
				try {
					var response = await apiFetch({ path: '/agentwp/v1/settings' });
					if (!isMounted) {
						return;
					}

					if (response && response.success) {
						var nextSettings = normalizeSettings(response.data.settings);
						setSettings(nextSettings);
						setSavedSettings(nextSettings);
						setApiKeyLast4(response.data.api_key_last4 || '');
						setHasApiKey(Boolean(response.data.has_api_key));
					} else {
						setNotice({
							status: 'error',
							message: __('Unable to load settings.', 'agentwp'),
						});
					}
				} catch (error) {
					if (!isMounted) {
						return;
					}
					setNotice({
						status: 'error',
						message: getErrorMessage(error, __('Unable to load settings.', 'agentwp')),
					});
				}
			}

			async function fetchUsage() {
				setUsageLoading(true);
				try {
					var response = await apiFetch({ path: '/agentwp/v1/usage?period=month' });
					if (isMounted && response && response.success) {
						var nextUsage = normalizeUsage(response.data);
						setUsage(nextUsage);
						setUsageBaseline(function (previous) {
							if (!previous || previous.period_start !== nextUsage.period_start) {
								return {
									total_tokens: nextUsage.total_tokens,
									total_cost_usd: nextUsage.total_cost_usd,
									period_start: nextUsage.period_start,
								};
							}
							return previous;
						});
					}
				} catch (error) {
					if (isMounted) {
						setNotice({
							status: 'error',
							message: getErrorMessage(error, __('Unable to load usage stats.', 'agentwp')),
						});
					}
				} finally {
					if (isMounted) {
						setUsageLoading(false);
					}
				}
			}

			Promise.all([fetchSettings(), fetchUsage()]).finally(function () {
				if (isMounted) {
					setLoading(false);
				}
			});

			return function () {
				isMounted = false;
			};
		}, []);

		function updateSetting(key, value) {
			setSettings(function (prev) {
				var next = Object.assign({}, prev);
				next[key] = value;
				return next;
			});
		}

		function saveSettings(nextSettings, successMessage) {
			var payload = {
				model: nextSettings.model,
				budget_limit: nextSettings.budget_limit,
				hotkey: nextSettings.hotkey,
				theme: nextSettings.theme,
			};
			var previous = savedSettings;

			setSettings(nextSettings);
			setSavedSettings(nextSettings);
			setSaving(true);
			setNotice({
				status: 'info',
				message: __('Saving settings...', 'agentwp'),
			});

			apiFetch({
				path: '/agentwp/v1/settings',
				method: 'POST',
				data: payload,
			})
				.then(function (response) {
					if (response && response.success) {
						var saved = normalizeSettings(response.data.settings);
						setSettings(saved);
						setSavedSettings(saved);
						setNotice({
							status: 'success',
							message: successMessage || __('Settings saved.', 'agentwp'),
						});
					} else {
						setSettings(previous);
						setSavedSettings(previous);
						setNotice({
							status: 'error',
							message: response && response.error && response.error.message
								? response.error.message
								: __('Unable to save settings.', 'agentwp'),
						});
					}
				})
				.catch(function (error) {
					setSettings(previous);
					setSavedSettings(previous);
					setNotice({
						status: 'error',
						message: getErrorMessage(error, __('Unable to save settings.', 'agentwp')),
					});
				})
				.finally(function () {
					setSaving(false);
				});
		}

		function handleSave() {
			saveSettings(settings);
		}

		function handleValidateKey() {
			var candidate = apiKeyInput.trim();
			if (!candidate) {
				setValidationNotice({
					status: 'error',
					message: __('Enter an API key to validate.', 'agentwp'),
				});
				return;
			}

			setApiKeyBusy(true);
			setValidationNotice({
				status: 'info',
				message: __('Validating API key...', 'agentwp'),
			});

			var timeoutId;
			var timeoutPromise = new Promise(function (resolve, reject) {
				timeoutId = setTimeout(function () {
					reject(new Error(__('Validation timed out.', 'agentwp')));
				}, 3000);
			});

			Promise.race([
				apiFetch({
					path: '/agentwp/v1/settings/api-key',
					method: 'POST',
					data: { api_key: candidate },
				}),
				timeoutPromise,
			])
				.then(function (response) {
					if (response && response.success) {
						setApiKeyInput('');
						setApiKeyLast4(response.data.last4 || '');
						setHasApiKey(Boolean(response.data.stored));
						setValidationNotice({
							status: 'success',
							message: __('API key validated and saved.', 'agentwp'),
						});
					} else {
						setValidationNotice({
							status: 'error',
							message: response && response.error && response.error.message
								? response.error.message
								: __('API key validation failed.', 'agentwp'),
						});
					}
				})
				.catch(function (error) {
					setValidationNotice({
						status: 'error',
						message: getErrorMessage(error, __('API key validation failed.', 'agentwp')),
					});
				})
				.finally(function () {
					if (timeoutId) {
						clearTimeout(timeoutId);
					}
					setApiKeyBusy(false);
				});
		}

		function handleExport() {
			var exportData = {
				model: settings.model,
				budget_limit: settings.budget_limit,
				hotkey: settings.hotkey,
				theme: settings.theme,
				dark_mode: settings.theme === 'dark',
			};

			var blob = new Blob([JSON.stringify(exportData, null, 2)], {
				type: 'application/json',
			});
			var url = window.URL.createObjectURL(blob);
			var link = document.createElement('a');
			link.href = url;
			link.download = 'agentwp-settings.json';
			document.body.appendChild(link);
			link.click();
			link.remove();
			window.URL.revokeObjectURL(url);
		}

		function handleImport(event) {
			var file = event.target.files && event.target.files[0];
			if (!file) {
				return;
			}

			var reader = new FileReader();
			reader.onload = function (loadEvent) {
				try {
					var parsed = JSON.parse(loadEvent.target.result);
					var nextSettings = normalizeSettings({
						model: parsed.model || settings.model,
						budget_limit: Number.isFinite(parseFloat(parsed.budget_limit))
							? parseFloat(parsed.budget_limit)
							: settings.budget_limit,
						hotkey: parsed.hotkey || settings.hotkey,
						theme: parsed.theme || (parsed.dark_mode ? 'dark' : settings.theme),
					});

					saveSettings(nextSettings, __('Settings imported and saved.', 'agentwp'));
				} catch (error) {
					setNotice({
						status: 'error',
						message: __('Imported JSON is invalid.', 'agentwp'),
					});
				}
			};
			reader.readAsText(file);
			event.target.value = '';
		}

		function refreshUsage() {
			setUsageLoading(true);
			apiFetch({ path: '/agentwp/v1/usage?period=month' })
				.then(function (response) {
					if (response && response.success) {
						var nextUsage = normalizeUsage(response.data);
						setUsage(nextUsage);
						setUsageBaseline(function (previous) {
							if (!previous || previous.period_start !== nextUsage.period_start) {
								return {
									total_tokens: nextUsage.total_tokens,
									total_cost_usd: nextUsage.total_cost_usd,
									period_start: nextUsage.period_start,
								};
							}
							return previous;
						});
					}
				})
				.catch(function (error) {
					setNotice({
						status: 'error',
						message: getErrorMessage(error, __('Unable to refresh usage stats.', 'agentwp')),
					});
				})
				.finally(function () {
					setUsageLoading(false);
				});
		}

		if (loading) {
			return el(
				'div',
				{ className: 'agentwp-settings__loading' },
				el(Spinner, null),
				el('span', null, __('Loading AgentWP settings...', 'agentwp'))
			);
		}

		var apiKeyHelp = hasApiKey
			? __('Stored key ending in ****', 'agentwp') + apiKeyLast4
			: __('No API key stored.', 'agentwp');

		var sessionTokens = usageBaseline
			? Math.max(0, usage.total_tokens - usageBaseline.total_tokens)
			: 0;
		var sessionCost = usageBaseline
			? Math.max(0, usage.total_cost_usd - usageBaseline.total_cost_usd)
			: 0;

		var budgetLimit = settings.budget_limit || 0;
		var budgetRatio = budgetLimit > 0 ? usage.total_cost_usd / budgetLimit : 0;
		var budgetPercent = Math.min(100, Math.round(budgetRatio * 100));
		var statusClassName = 'agentwp-settings__status-bar';
		if (budgetLimit > 0 && budgetRatio >= 1) {
			statusClassName += ' agentwp-settings__status-bar--limit';
		} else if (budgetLimit > 0 && budgetRatio >= 0.8) {
			statusClassName += ' agentwp-settings__status-bar--warning';
		}

		var budgetFillClass = 'agentwp-settings__budget-fill';
		if (budgetLimit > 0 && budgetRatio >= 1) {
			budgetFillClass += ' agentwp-settings__budget-fill--limit';
		} else if (budgetLimit > 0 && budgetRatio >= 0.8) {
			budgetFillClass += ' agentwp-settings__budget-fill--warning';
		}

		var budgetNotice = null;
		if (budgetLimit > 0 && budgetRatio >= 1) {
			budgetNotice = {
				status: 'error',
				message: __('Monthly budget limit reached. Consider lowering usage or raising the limit.', 'agentwp'),
			};
		} else if (budgetLimit > 0 && budgetRatio >= 0.8) {
			budgetNotice = {
				status: 'warning',
				message: __('Approaching monthly budget limit. Usage is above 80%.', 'agentwp'),
			};
		}

		return el(
			'div',
			{ className: 'agentwp-settings' },
			el(
				'div',
				{ className: 'agentwp-settings__header' },
				el('h1', null, __('AgentWP Settings', 'agentwp')),
				el(
					'div',
					{ className: 'agentwp-settings__actions' },
					el(Button, { isPrimary: true, onClick: handleSave, isBusy: saving, disabled: saving }, __('Save Settings', 'agentwp'))
				)
			),
			notice
				? el(
						Notice,
						{
							status: notice.status,
							isDismissible: true,
							onRemove: function () {
								setNotice(null);
							},
							className: 'agentwp-settings__notice',
						},
						notice.message
				  )
				: null,
			budgetNotice
				? el(
						Notice,
						{
							status: budgetNotice.status,
							isDismissible: false,
							className: 'agentwp-settings__notice',
						},
						budgetNotice.message
				  )
				: null,
			el(
				'div',
				{ className: 'agentwp-settings__grid' },
				el(
					Card,
					{ className: 'agentwp-settings__full' },
					el(CardHeader, null, el('h2', null, __('API Configuration', 'agentwp'))),
					el(
						CardBody,
						null,
						el(
							'div',
							{ className: 'agentwp-settings__api-row' },
							el(TextControl, {
								label: __('OpenAI API Key', 'agentwp'),
								type: 'password',
								value: apiKeyInput,
								onChange: setApiKeyInput,
								placeholder: hasApiKey ? '****' + apiKeyLast4 : '',
								help: apiKeyHelp,
							}),
							el(
								'div',
								{ className: 'agentwp-settings__api-action' },
								el(
									Button,
									{
										isSecondary: true,
										onClick: handleValidateKey,
										isBusy: apiKeyBusy,
										disabled: apiKeyBusy,
									},
									__('Validate Key', 'agentwp')
								)
							)
						),
						validationNotice
							? el(
									Notice,
									{
										status: validationNotice.status,
										isDismissible: true,
										onRemove: function () {
											setValidationNotice(null);
										},
										className: 'agentwp-settings__notice',
									},
									validationNotice.message
							  )
							: null
					)
				),
				el(
					Card,
					null,
					el(CardHeader, null, el('h2', null, __('Model Selection', 'agentwp'))),
					el(
						CardBody,
						null,
						el(SelectControl, {
							label: __('Model', 'agentwp'),
							value: settings.model,
							options: [
								{ label: __('gpt-4o (recommended)', 'agentwp'), value: 'gpt-4o' },
								{ label: __('gpt-4o-mini (budget)', 'agentwp'), value: 'gpt-4o-mini' },
							],
							onChange: function (value) {
								updateSetting('model', value);
							},
							help: __('Choose the default model for AgentWP requests.', 'agentwp'),
						})
					)
				),
				el(
					Card,
					null,
					el(CardHeader, null, el('h2', null, __('Interface Preferences', 'agentwp'))),
					el(
						CardBody,
						null,
						el(TextControl, {
							label: __('Command Deck Hotkey', 'agentwp'),
							value: settings.hotkey,
							onChange: function (value) {
								updateSetting('hotkey', value);
							},
							help: __('Default: Cmd+K / Ctrl+K', 'agentwp'),
						}),
						el(ToggleControl, {
							label: __('Enable dark mode by default', 'agentwp'),
							checked: settings.theme === 'dark',
							onChange: function (value) {
								updateSetting('theme', value ? 'dark' : 'light');
							},
						})
					)
				),
				el(
					Card,
					null,
					el(CardHeader, null, el('h2', null, __('Usage Statistics', 'agentwp'))),
					el(
						CardBody,
						null,
						el(
							'div',
							{ className: statusClassName },
							el(
								'div',
								{ className: 'agentwp-settings__status-item' },
								el('p', { className: 'agentwp-settings__status-label' }, __('Session tokens used', 'agentwp')),
								el('p', { className: 'agentwp-settings__status-value' }, formatNumber(sessionTokens))
							),
							el(
								'div',
								{ className: 'agentwp-settings__status-item' },
								el('p', { className: 'agentwp-settings__status-label' }, __('Session cost', 'agentwp')),
								el('p', { className: 'agentwp-settings__status-value' }, formatCurrency(sessionCost))
							),
							el(
								'div',
								{ className: 'agentwp-settings__status-item' },
								el('p', { className: 'agentwp-settings__status-label' }, __('Account-wide monthly total', 'agentwp')),
								el('p', { className: 'agentwp-settings__status-value' }, formatCurrency(usage.total_cost_usd)),
								el('p', { className: 'agentwp-settings__status-sub' }, formatNumber(usage.total_tokens) + ' ' + __('tokens', 'agentwp'))
							)
						),
						el(
							'div',
							{ className: 'agentwp-settings__stats' },
							el(
								'div',
								{ className: 'agentwp-settings__stat' },
								el('p', { className: 'agentwp-settings__stat-label' }, __('Monthly tokens', 'agentwp')),
								el('p', { className: 'agentwp-settings__stat-value' }, formatNumber(usage.total_tokens))
							),
							el(
								'div',
								{ className: 'agentwp-settings__stat' },
								el('p', { className: 'agentwp-settings__stat-label' }, __('Monthly cost', 'agentwp')),
								el('p', { className: 'agentwp-settings__stat-value' }, formatCurrency(usage.total_cost_usd))
							),
							el(
								'div',
								{ className: 'agentwp-settings__stat' },
								el('p', { className: 'agentwp-settings__stat-label' }, __('Last updated', 'agentwp')),
								el('p', { className: 'agentwp-settings__stat-value' }, formatDate(usage.period_end))
							)
						),
						el(
							'div',
							{ className: 'agentwp-settings__budget' },
							el(TextControl, {
								label: __('Monthly budget limit (USD)', 'agentwp'),
								type: 'number',
								min: 0,
								step: '0.01',
								value: settings.budget_limit,
								onChange: function (value) {
									var nextValue = parseFloat(value);
									if (!Number.isFinite(nextValue) || nextValue < 0) {
										nextValue = 0;
									}
									updateSetting('budget_limit', nextValue);
								},
								help: __('Set a monthly budget to trigger usage warnings at 80% and 100%.', 'agentwp'),
							}),
							budgetLimit > 0
								? el(
										'div',
										null,
										el(
											'div',
											{ className: 'agentwp-settings__budget-bar' },
											el('div', {
												className: budgetFillClass,
												style: { width: budgetPercent + '%' },
											})
										),
										el(
											'p',
											{ className: 'agentwp-settings__budget-meta' },
											formatCurrency(usage.total_cost_usd) +
												' ' +
												__('of', 'agentwp') +
												' ' +
												formatCurrency(budgetLimit) +
												' (' +
												budgetPercent +
												'%)'
										)
								  )
								: null
						),
						el(
							'div',
							{ className: 'agentwp-settings__usage-actions' },
							el(
								Button,
								{
									isSecondary: true,
									onClick: refreshUsage,
									isBusy: usageLoading,
									disabled: usageLoading,
								},
								__('Refresh usage', 'agentwp')
							)
						)
					)
				),
				el(
					Card,
					{ className: 'agentwp-settings__full' },
					el(CardHeader, null, el('h2', null, __('Export / Import', 'agentwp'))),
					el(
						CardBody,
						null,
						el(
							Flex,
							{ className: 'agentwp-settings__import-export' },
							el(
								FlexItem,
								null,
								el(Button, { isSecondary: true, onClick: handleExport }, __('Export Settings', 'agentwp'))
							),
							el(
								FlexItem,
								null,
								el(FormFileUpload, {
									accept: 'application/json',
									onChange: handleImport,
									isSecondary: true,
								}, __('Import Settings', 'agentwp'))
							)
						),
						el(
							'p',
							{ className: 'agentwp-settings__hint' },
							__('API keys are not included in exports.', 'agentwp')
						)
					)
				)
			)
		);
	}

	function boot() {
		var root = document.getElementById('agentwp-root') || document.getElementById('agentwp-admin-root');
		if (!root || root.dataset.agentwpInitialized) {
			return;
		}

		render(el(SettingsApp), root);
		root.dataset.agentwpInitialized = 'true';
	}

	document.addEventListener('DOMContentLoaded', boot);
})();
