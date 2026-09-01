<?php
/**
 * Plugin Name: Agent CRM Sales Agents
 * Description: Displays sales agents from an Agent CRM backend instance with configurable connection, cache, and display settings.
 * Version: 1.2.0
 * Author: Essence
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: agent-crm-sales-agents
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Agent_CRM_Sales_Agents_Plugin
{
    private const OPTION_NAME = 'agent_crm_sales_agents_settings';
    private const CACHE_GROUP = 'agent_crm_sales_agents';
    private const REST_NAMESPACE = 'agent-crm-sales-agents/v1';
    private const REST_ROUTE = '/agents';
    private const LEGACY_ENDPOINT_PATH = '/api/v1/agent';
    private const DEFAULT_ENDPOINT_PATH = '/api/v1/websites/sales-agents/listing';
    private const DEFAULT_SIGNUP_ENDPOINT_PATH = '/api/v1/websites/sales-agents/signup';
    private const DEFAULT_APPOINTMENTS_ENDPOINT_PATH = '/api/v1/websites/sales-agents/{id}/appointments';

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'render_admin_notices']);
        add_action('admin_post_agent_crm_sales_agents_test_connection', [$this, 'handle_test_connection']);
        add_action('wp_enqueue_scripts', [$this, 'register_frontend_assets']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_shortcode('agent_crm_sales_agents', [$this, 'render_shortcode']);
    }

    public static function activate(): void
    {
        if (!get_option(self::OPTION_NAME)) {
            add_option(self::OPTION_NAME, self::default_settings());
        }
    }

    public static function deactivate(): void
    {
        self::clear_all_cache();
    }

    public function register_admin_menu(): void
    {
        add_options_page(
            __('Agent CRM Sales Agents', 'agent-crm-sales-agents'),
            __('Agent CRM Agents', 'agent-crm-sales-agents'),
            'manage_options',
            'agent-crm-sales-agents',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'agent_crm_sales_agents_settings_group',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => self::default_settings(),
            ]
        );

        add_settings_section(
            'agent_crm_sales_agents_connection',
            __('CRM Connection', 'agent-crm-sales-agents'),
            function (): void {
                echo '<p>' . esc_html__('Configure the CRM backend instance this WordPress site should read from.', 'agent-crm-sales-agents') . '</p>';
            },
            'agent-crm-sales-agents'
        );

        $this->add_field('base_url', __('CRM Base URL', 'agent-crm-sales-agents'), 'url');
        $this->add_field('endpoint_path', __('Listing Endpoint Path', 'agent-crm-sales-agents'), 'text');
        $this->add_field('signup_endpoint_path', __('Signup Endpoint Path', 'agent-crm-sales-agents'), 'text');
        $this->add_field('appointments_endpoint_path', __('Appointments Endpoint Path', 'agent-crm-sales-agents'), 'text');
        $this->add_field('tenant_id', __('Instance / Tenant ID', 'agent-crm-sales-agents'), 'number');
        $this->add_field('campaign_id', __('Campaign ID', 'agent-crm-sales-agents'), 'number');
        $this->add_field('client_id', __('Website Client ID', 'agent-crm-sales-agents'), 'text');
        $this->add_field('client_secret', __('Website Client Secret', 'agent-crm-sales-agents'), 'password');

        add_settings_section(
            'agent_crm_sales_agents_display',
            __('Display', 'agent-crm-sales-agents'),
            function (): void {
                echo '<p>' . esc_html__('Control how agents appear on public pages.', 'agent-crm-sales-agents') . '</p>';
            },
            'agent-crm-sales-agents'
        );

        $this->add_field('title', __('List Title', 'agent-crm-sales-agents'), 'text');
        $this->add_field('layout', __('Layout', 'agent-crm-sales-agents'), 'select');
        $this->add_field('show_email', __('Show Email', 'agent-crm-sales-agents'), 'checkbox');
        $this->add_field('show_phone', __('Show Phone', 'agent-crm-sales-agents'), 'checkbox');
        $this->add_field('show_distance', __('Show Distance', 'agent-crm-sales-agents'), 'checkbox');
        $this->add_field('cache_ttl', __('Cache Time (seconds)', 'agent-crm-sales-agents'), 'number');
        $this->add_field('empty_message', __('Empty Message', 'agent-crm-sales-agents'), 'text');
    }

    private function add_field(string $key, string $label, string $type): void
    {
        add_settings_field(
            'agent_crm_sales_agents_' . $key,
            $label,
            function () use ($key, $type): void {
                $settings = $this->get_settings();
                $value = $settings[$key] ?? '';
                $name = self::OPTION_NAME . '[' . $key . ']';

                if ($type === 'checkbox') {
                    printf(
                        '<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
                        esc_attr($name),
                        checked((bool) $value, true, false),
                        esc_html__('Enabled', 'agent-crm-sales-agents')
                    );
                    return;
                }

                if ($type === 'select') {
                    printf('<select name="%s">', esc_attr($name));
                    foreach (['grid' => __('Grid', 'agent-crm-sales-agents'), 'list' => __('List', 'agent-crm-sales-agents')] as $option_value => $option_label) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($option_value),
                            selected($value, $option_value, false),
                            esc_html($option_label)
                        );
                    }
                    echo '</select>';
                    return;
                }

                $input_type = in_array($type, ['url', 'number', 'password'], true) ? $type : 'text';
                $autocomplete = $key === 'client_secret' ? ' autocomplete="new-password"' : '';
                $display_value = $key === 'client_secret' ? '' : (string) $value;
                $placeholder = $key === 'client_secret' && $value !== ''
                    ? __('Saved - leave blank to keep unchanged', 'agent-crm-sales-agents')
                    : '';

                printf(
                    '<input type="%s" class="regular-text" name="%s" value="%s" placeholder="%s"%s>',
                    esc_attr($input_type),
                    esc_attr($name),
                    esc_attr($display_value),
                    esc_attr($placeholder),
                    $autocomplete
                );

                if ($key === 'client_secret' && $value !== '') {
                    echo '<p class="description">' . esc_html__('A Website Client Secret is saved. Enter a new value only if you want to replace it.', 'agent-crm-sales-agents') . '</p>';
                }
            },
            'agent-crm-sales-agents',
            $this->starts_with($key, 'show_') || in_array($key, ['title', 'layout', 'cache_ttl', 'empty_message'], true)
                ? 'agent_crm_sales_agents_display'
                : 'agent_crm_sales_agents_connection'
        );
    }

    public function sanitize_settings(array $input): array
    {
        $previous = $this->get_settings();

        $settings = [
            'base_url' => esc_url_raw(trim((string) ($input['base_url'] ?? ''))),
            'endpoint_path' => $this->sanitize_endpoint_path($input['endpoint_path'] ?? self::DEFAULT_ENDPOINT_PATH, self::DEFAULT_ENDPOINT_PATH),
            'signup_endpoint_path' => $this->sanitize_endpoint_path($input['signup_endpoint_path'] ?? self::DEFAULT_SIGNUP_ENDPOINT_PATH, self::DEFAULT_SIGNUP_ENDPOINT_PATH),
            'appointments_endpoint_path' => $this->sanitize_endpoint_path($input['appointments_endpoint_path'] ?? self::DEFAULT_APPOINTMENTS_ENDPOINT_PATH, self::DEFAULT_APPOINTMENTS_ENDPOINT_PATH),
            'tenant_id' => absint($input['tenant_id'] ?? 0),
            'campaign_id' => absint($input['campaign_id'] ?? 1),
            'client_id' => sanitize_text_field($input['client_id'] ?? ($input['service_username'] ?? '')),
            'client_secret' => (string) ($input['client_secret'] ?? ($input['service_password'] ?? '')),
            'default_lead_id' => absint($input['default_lead_id'] ?? 0),
            'title' => sanitize_text_field($input['title'] ?? ''),
            'layout' => in_array(($input['layout'] ?? 'grid'), ['grid', 'list'], true) ? $input['layout'] : 'grid',
            'show_email' => !empty($input['show_email']),
            'show_phone' => !empty($input['show_phone']),
            'show_distance' => !empty($input['show_distance']),
            'cache_ttl' => max(0, absint($input['cache_ttl'] ?? 300)),
            'empty_message' => sanitize_text_field($input['empty_message'] ?? ''),
        ];

        if ($settings['client_secret'] === '' && !empty($previous['client_secret'])) {
            $settings['client_secret'] = $previous['client_secret'];
        }

        if ($settings['client_secret'] === '' && !empty($previous['service_password'])) {
            $settings['client_secret'] = $previous['service_password'];
        }

        if ($settings['endpoint_path'] === '' || $settings['endpoint_path'] === self::LEGACY_ENDPOINT_PATH) {
            $settings['endpoint_path'] = self::DEFAULT_ENDPOINT_PATH;
        }

        if (strpos($settings['appointments_endpoint_path'], '{id}') === false) {
            $settings['appointments_endpoint_path'] = self::DEFAULT_APPOINTMENTS_ENDPOINT_PATH;
        }

        self::clear_all_cache();

        return array_merge(self::default_settings(), $settings);
    }

    public function render_admin_notices(): void
    {
        if (!current_user_can('manage_options') || empty($_GET['agent_crm_test'])) {
            return;
        }

        $type = sanitize_text_field(wp_unslash($_GET['agent_crm_test']));
        $message = isset($_GET['agent_crm_message'])
            ? sanitize_text_field(wp_unslash($_GET['agent_crm_message']))
            : '';

        if ($message === '') {
            return;
        }

        $class = $type === 'passed' ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';
        printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html($message));
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $test_url = wp_nonce_url(
            admin_url('admin-post.php?action=agent_crm_sales_agents_test_connection'),
            'agent_crm_sales_agents_test_connection'
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Agent CRM Sales Agents', 'agent-crm-sales-agents'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('agent_crm_sales_agents_settings_group');
                do_settings_sections('agent-crm-sales-agents');
                submit_button();
                ?>
            </form>
            <hr>
            <p>
                <a href="<?php echo esc_url($test_url); ?>" class="button button-secondary">
                    <?php esc_html_e('Test Connection', 'agent-crm-sales-agents'); ?>
                </a>
            </p>
            <p>
                <?php esc_html_e('Shortcode:', 'agent-crm-sales-agents'); ?>
                <code>[agent_crm_sales_agents]</code>
                <code>[agent_crm_sales_agents layout="list"]</code>
            </p>
        </div>
        <?php
    }

    public function handle_test_connection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to test this connection.', 'agent-crm-sales-agents'));
        }

        check_admin_referer('agent_crm_sales_agents_test_connection');

        $response = $this->fetch_agents([]);
        $redirect = add_query_arg(
            [
                'page' => 'agent-crm-sales-agents',
                'agent_crm_test' => is_wp_error($response) ? 'failed' : 'passed',
                'agent_crm_message' => is_wp_error($response) ? $response->get_error_message() : __('Connection successful.', 'agent-crm-sales-agents'),
            ],
            admin_url('options-general.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function register_frontend_assets(): void
    {
        wp_register_style(
            'agent-crm-sales-agents',
            plugins_url('assets/css/agent-crm-sales-agents.css', __FILE__),
            [],
            '1.2.0'
        );

        wp_register_script(
            'agent-crm-sales-agents',
            plugins_url('assets/js/agent-crm-sales-agents.js', __FILE__),
            [],
            '1.2.0',
            true
        );
    }

    public function register_rest_routes(): void
    {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_agents'],
                'permission_callback' => '__return_true',
                'args' => [
                    'lead_id' => [
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/sales-agents/listing',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_sales_agents_listing'],
                'permission_callback' => '__return_true',
                'args' => [
                    'tenantId' => [
                        'sanitize_callback' => 'absint',
                    ],
                    'pageNumber' => [
                        'sanitize_callback' => 'absint',
                    ],
                    'pageSize' => [
                        'sanitize_callback' => 'absint',
                    ],
                    'search' => [
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/sales-agents/signup',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_sales_agents_signup'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/sales-agents/(?P<id>\d+)/appointments',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_sales_agent_appointments'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required' => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function rest_agents(WP_REST_Request $request): WP_REST_Response
    {
        $lead_id = absint($request->get_param('lead_id'));
        $result = $this->fetch_agents(['lead_id' => $lead_id]);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
                'data' => [],
            ], 502);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function rest_sales_agents_listing(WP_REST_Request $request): WP_REST_Response
    {
        $body = $this->get_json_request_body($request);
        $body = array_merge(
            [
                'tenantId' => $this->get_settings()['tenant_id'],
                'pageNumber' => 1,
                'pageSize' => 100,
            ],
            $body
        );
        unset($body['campaignId'], $body['filtercampaignId']);

        return $this->rest_proxy_response(
            $this->proxy_website_request((string) $this->get_settings()['endpoint_path'], $body, false)
        );
    }

    public function rest_sales_agents_signup(WP_REST_Request $request): WP_REST_Response
    {
        return $this->rest_proxy_response(
            $this->proxy_website_request((string) $this->get_settings()['signup_endpoint_path'], $this->get_json_request_body($request))
        );
    }

    public function rest_sales_agent_appointments(WP_REST_Request $request): WP_REST_Response
    {
        $sales_agent_id = absint($request->get_param('id'));

        if ($sales_agent_id < 1) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Valid sales agent ID is required.', 'agent-crm-sales-agents'),
            ], 400);
        }

        return $this->rest_proxy_response(
            $this->proxy_website_request(
                $this->endpoint_with_sales_agent_id((string) $this->get_settings()['appointments_endpoint_path'], $sales_agent_id),
                $this->get_json_request_body($request)
            )
        );
    }

    public function render_shortcode(array $atts = []): string
    {
        return $this->render_listing($atts);
    }

    public function render_listing(array $atts = []): string
    {
        $settings = $this->get_settings();
        $atts = shortcode_atts(
            [
                'lead_id' => $settings['default_lead_id'],
                'layout' => $settings['layout'],
                'title' => $settings['title'],
                'show_email' => $settings['show_email'] ? '1' : '0',
                'show_phone' => $settings['show_phone'] ? '1' : '0',
                'show_distance' => $settings['show_distance'] ? '1' : '0',
                'page_size' => 100,
                'page_number' => 1,
                'search' => '',
            ],
            $atts,
            'agent_crm_sales_agents'
        );

        wp_enqueue_style('agent-crm-sales-agents');

        $agents = $this->fetch_agents([
            'lead_id' => absint($atts['lead_id']),
            'page_size' => absint($atts['page_size']),
            'page_number' => absint($atts['page_number']),
            'search' => sanitize_text_field((string) $atts['search']),
        ]);
        $layout = in_array($atts['layout'], ['grid', 'list'], true) ? $atts['layout'] : $settings['layout'];
        $show_email = filter_var($atts['show_email'], FILTER_VALIDATE_BOOLEAN);
        $show_phone = filter_var($atts['show_phone'], FILTER_VALIDATE_BOOLEAN);
        $show_distance = filter_var($atts['show_distance'], FILTER_VALIDATE_BOOLEAN);

        ob_start();
        ?>
        <section class="agent-crm-sales-agents agent-crm-sales-agents--<?php echo esc_attr($layout); ?>">
            <?php if (!empty($atts['title'])) : ?>
                <h2 class="agent-crm-sales-agents__title"><?php echo esc_html($atts['title']); ?></h2>
            <?php endif; ?>

            <?php if (is_wp_error($agents)) : ?>
                <p class="agent-crm-sales-agents__message"><?php echo esc_html($agents->get_error_message()); ?></p>
            <?php elseif (empty($agents)) : ?>
                <p class="agent-crm-sales-agents__message"><?php echo esc_html($settings['empty_message']); ?></p>
            <?php else : ?>
                <div class="agent-crm-sales-agents__items">
                    <?php foreach ($agents as $agent) : ?>
                        <?php $normalized = $this->normalize_agent($agent); ?>
                        <article class="agent-crm-sales-agents__card">
                            <?php if (!empty($normalized['profilePicture'])) : ?>
                                <img class="agent-crm-sales-agents__avatar" src="<?php echo esc_url($normalized['profilePicture']); ?>" alt="<?php echo esc_attr($normalized['name']); ?>">
                            <?php else : ?>
                                <div class="agent-crm-sales-agents__avatar agent-crm-sales-agents__avatar--initials" aria-hidden="true">
                                    <?php echo esc_html($normalized['initials']); ?>
                                </div>
                            <?php endif; ?>

                            <div class="agent-crm-sales-agents__body">
                                <h3 class="agent-crm-sales-agents__name"><?php echo esc_html($normalized['name']); ?></h3>
                                <?php if ($show_email && !empty($normalized['email'])) : ?>
                                    <a class="agent-crm-sales-agents__meta" href="mailto:<?php echo esc_attr($normalized['email']); ?>"><?php echo esc_html($normalized['email']); ?></a>
                                <?php endif; ?>
                                <?php if ($show_phone && !empty($normalized['phone'])) : ?>
                                    <a class="agent-crm-sales-agents__meta" href="tel:<?php echo esc_attr($normalized['phone']); ?>"><?php echo esc_html($normalized['phone']); ?></a>
                                <?php endif; ?>
                                <?php if ($show_distance && !empty($normalized['distance'])) : ?>
                                    <span class="agent-crm-sales-agents__meta"><?php echo esc_html($normalized['distance']); ?> <?php esc_html_e('miles away', 'agent-crm-sales-agents'); ?></span>
                                <?php endif; ?>
                                <span class="agent-crm-sales-agents__meta"><?php esc_html_e('Address:', 'agent-crm-sales-agents'); ?> <?php echo esc_html($normalized['licenseAddress'] ?: __('N/A', 'agent-crm-sales-agents')); ?></span>
                                <span class="agent-crm-sales-agents__meta"><?php esc_html_e('Zip Code:', 'agent-crm-sales-agents'); ?> <?php echo esc_html($normalized['licenseZipCode'] ?: __('N/A', 'agent-crm-sales-agents')); ?></span>
                                <div class="agent-crm-sales-agents__actions">
                                    <button
                                        type="button"
                                        class="btn btn-primary js-book-agent"
                                        data-agent-id="<?php echo esc_attr($normalized['id']); ?>"
                                        data-agent-name="<?php echo esc_attr($normalized['name']); ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#appointmentBookingModal"
                                    ><?php esc_html_e('Book Appointment', 'agent-crm-sales-agents'); ?></button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    private function fetch_agents(array $args)
    {
        $settings = $this->get_settings();
        $base_url = untrailingslashit($settings['base_url']);
        $client_id = (string) ($settings['client_id'] ?: ($settings['service_username'] ?? ''));
        $client_secret = (string) ($settings['client_secret'] ?: ($settings['service_password'] ?? ''));

        if ($base_url === '' || $client_id === '' || $client_secret === '' || empty($settings['tenant_id'])) {
            return new WP_Error('agent_crm_not_configured', __('Agent CRM plugin is not configured.', 'agent-crm-sales-agents'));
        }

        $endpoint_path = '/' . ltrim((string) $settings['endpoint_path'], '/');
        $request_body = [
            'tenantId' => absint($settings['tenant_id']),
            'pageSize' => max(1, absint($args['page_size'] ?? 100)),
            'pageNumber' => max(1, absint($args['page_number'] ?? 1)),
        ];

        $lead_id = absint($args['lead_id'] ?? $settings['default_lead_id']);

        if ($lead_id > 0) {
            $request_body['leadId'] = $lead_id;
        }

        if (!empty($args['search'])) {
            $request_body['search'] = sanitize_text_field((string) $args['search']);
        }

        $url = $base_url . $endpoint_path;
        $cache_key = $this->cache_key($url, $settings, $request_body);

        if ((int) $settings['cache_ttl'] > 0) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = wp_remote_post(
            $url,
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
                    'x-tenant-id' => (string) absint($settings['tenant_id']),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($request_body),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($body) && (!empty($body['message']) || !empty($body['error']))
                ? ($body['message'] ?? $body['error'])
                : sprintf(__('CRM request failed with HTTP %d.', 'agent-crm-sales-agents'), $status);

            return new WP_Error('agent_crm_request_failed', $message);
        }

        $agents = [];
        if (is_array($body)) {
            $agents = $body['data'] ?? $body['agents'] ?? $body;
        }

        if (!is_array($agents)) {
            return new WP_Error('agent_crm_invalid_response', __('CRM returned an unexpected agent response.', 'agent-crm-sales-agents'));
        }

        if ((int) $settings['cache_ttl'] > 0) {
            set_transient($cache_key, $agents, (int) $settings['cache_ttl']);
        }

        return $agents;
    }

    private function proxy_website_request(string $endpoint_path, array $request_body, bool $include_campaign = true)
    {
        $settings = $this->get_settings();
        $base_url = untrailingslashit($settings['base_url']);
        $client_id = (string) ($settings['client_id'] ?: ($settings['service_username'] ?? ''));
        $client_secret = (string) ($settings['client_secret'] ?: ($settings['service_password'] ?? ''));

        if ($base_url === '' || $client_id === '' || $client_secret === '' || empty($settings['tenant_id'])) {
            return new WP_Error('agent_crm_not_configured', __('Agent CRM plugin is not configured.', 'agent-crm-sales-agents'));
        }

        if (empty($request_body['tenantId'])) {
            $request_body['tenantId'] = absint($settings['tenant_id']);
        }

        if ($include_campaign && empty($request_body['campaignId']) && !empty($settings['campaign_id'])) {
            $request_body['campaignId'] = absint($settings['campaign_id']);
        }

        $url = $base_url . '/' . ltrim($endpoint_path, '/');
        $response = wp_remote_post(
            $url,
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
                    'x-tenant-id' => (string) absint($settings['tenant_id']),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($request_body),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            $body = [
                'success' => $status >= 200 && $status < 300,
                'message' => wp_remote_retrieve_body($response),
            ];
        }

        return [
            'status' => $status,
            'body' => $body,
        ];
    }

    private function rest_proxy_response($result): WP_REST_Response
    {
        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 502);
        }

        $status = isset($result['status']) ? (int) $result['status'] : 200;
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];

        return new WP_REST_Response($body, $status);
    }

    private function get_json_request_body(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        if (!is_array($body)) {
            $body = [];
        }

        foreach ($request->get_params() as $key => $value) {
            if ($key === 'id') {
                continue;
            }

            $body[$key] = $value;
        }

        return $body;
    }

    private function normalize_agent(array $agent): array
    {
        $first = trim((string) ($agent['firstName'] ?? ($agent['first_name'] ?? '')));
        $last = trim((string) ($agent['lastName'] ?? ($agent['last_name'] ?? '')));
        $name = trim($first . ' ' . $last);

        if ($name === '') {
            $name = trim((string) ($agent['name'] ?? ($agent['fullName'] ?? ($agent['full_name'] ?? ''))));
        }

        if ($name === '') {
            $name = __('Sales agent', 'agent-crm-sales-agents');
        }

        $phone = trim((string) ($agent['phone'] ?? ''));
        $phone_code = trim((string) ($agent['phoneCode'] ?? ''));

        if ($phone !== '' && $phone_code !== '' && !$this->starts_with($phone, '+')) {
            $phone = '+' . preg_replace('/\D+/', '', $phone_code) . preg_replace('/\D+/', '', $phone);
        }

        $location_parts = array_filter([
            $agent['city'] ?? '',
            $agent['state'] ?? '',
            $agent['pincode'] ?? ($agent['zipCode'] ?? ($agent['zip_code'] ?? '')),
        ]);

        return [
            'id' => (string) ($agent['salesAgentId'] ?? ($agent['agentId'] ?? ($agent['userId'] ?? ($agent['id'] ?? '')))),
            'name' => $name,
            'initials' => strtoupper(substr($first ?: $name, 0, 1) . substr($last, 0, 1)),
            'email' => sanitize_email($agent['email'] ?? ''),
            'phone' => $phone,
            'profilePicture' => esc_url_raw($agent['profilePicture'] ?? ($agent['profile_picture'] ?? ($agent['avatar'] ?? ''))),
            'distance' => isset($agent['distance']) ? (string) $agent['distance'] : '',
            'location' => implode(', ', array_map('sanitize_text_field', $location_parts)),
            'address' => sanitize_text_field((string) ($agent['address'] ?? '')),
            'licenseAddress' => sanitize_text_field((string) ($agent['licenceAddress'] ?? ($agent['licenseAddress'] ?? ($agent['license_address'] ?? '')))),
            'licenseZipCode' => sanitize_text_field((string) ($agent['licenceZipCode'] ?? ($agent['licenseZipCode'] ?? ($agent['license_zip_code'] ?? '')))),
            'serviceArea' => sanitize_text_field((string) ($agent['serviceArea'] ?? ($agent['leadCoverageInMiles'] ?? ''))),
            'specialties' => sanitize_text_field((string) ($agent['specialties'] ?? ($agent['specialty'] ?? ''))),
            'experience' => sanitize_text_field((string) ($agent['yearsExperience'] ?? ($agent['years_experience'] ?? ''))),
            'license' => sanitize_text_field((string) ($agent['licenceNumber'] ?? ($agent['licenseNumber'] ?? ($agent['license_number'] ?? '')))),
        ];
    }

    private function get_settings(): array
    {
        $settings = get_option(self::OPTION_NAME, []);

        $settings = array_merge(self::default_settings(), is_array($settings) ? $settings : []);

        if ($settings['client_id'] === '' && $settings['service_username'] !== '') {
            $settings['client_id'] = $settings['service_username'];
        }

        if ($settings['client_secret'] === '' && $settings['service_password'] !== '') {
            $settings['client_secret'] = $settings['service_password'];
        }

        if ($settings['endpoint_path'] === '' || $settings['endpoint_path'] === self::LEGACY_ENDPOINT_PATH) {
            $settings['endpoint_path'] = self::DEFAULT_ENDPOINT_PATH;
        }

        $settings['endpoint_path'] = $this->sanitize_endpoint_path($settings['endpoint_path'], self::DEFAULT_ENDPOINT_PATH);
        $settings['signup_endpoint_path'] = $this->sanitize_endpoint_path($settings['signup_endpoint_path'], self::DEFAULT_SIGNUP_ENDPOINT_PATH);
        $settings['appointments_endpoint_path'] = $this->sanitize_endpoint_path($settings['appointments_endpoint_path'], self::DEFAULT_APPOINTMENTS_ENDPOINT_PATH);

        if ($settings['signup_endpoint_path'] === '') {
            $settings['signup_endpoint_path'] = self::DEFAULT_SIGNUP_ENDPOINT_PATH;
        }

        if ($settings['appointments_endpoint_path'] === '' || strpos($settings['appointments_endpoint_path'], '{id}') === false) {
            $settings['appointments_endpoint_path'] = self::DEFAULT_APPOINTMENTS_ENDPOINT_PATH;
        }

        return $settings;
    }

    private static function default_settings(): array
    {
        return [
            'base_url' => '',
            'endpoint_path' => self::DEFAULT_ENDPOINT_PATH,
            'signup_endpoint_path' => self::DEFAULT_SIGNUP_ENDPOINT_PATH,
            'appointments_endpoint_path' => self::DEFAULT_APPOINTMENTS_ENDPOINT_PATH,
            'tenant_id' => 1,
            'campaign_id' => 1,
            'client_id' => '',
            'client_secret' => '',
            'service_username' => '',
            'service_password' => '',
            'default_lead_id' => 0,
            'title' => __('Sales Agents', 'agent-crm-sales-agents'),
            'layout' => 'grid',
            'show_email' => true,
            'show_phone' => true,
            'show_distance' => true,
            'cache_ttl' => 300,
            'empty_message' => __('No sales agents are available right now.', 'agent-crm-sales-agents'),
        ];
    }

    private function cache_key(string $url, array $settings, array $request_body = []): string
    {
        return 'agent_crm_sales_agents_' . md5($url . '|' . (string) $settings['tenant_id'] . '|' . wp_json_encode($request_body));
    }

    private function sanitize_endpoint_path($path, string $default): string
    {
        $path = sanitize_text_field($path);
        $path = trim($path);

        if ($path === '') {
            return $default;
        }

        return '/' . ltrim($path, '/');
    }

    private function endpoint_with_sales_agent_id(string $endpoint_path, int $sales_agent_id): string
    {
        return str_replace('{id}', (string) $sales_agent_id, $endpoint_path);
    }

    private function starts_with(string $value, string $prefix): bool
    {
        return substr($value, 0, strlen($prefix)) === $prefix;
    }

    private static function clear_all_cache(): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_agent_crm_sales_agents_') . '%',
                $wpdb->esc_like('_transient_timeout_agent_crm_sales_agents_') . '%'
            )
        );
    }
}

register_activation_hook(__FILE__, ['Agent_CRM_Sales_Agents_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Agent_CRM_Sales_Agents_Plugin', 'deactivate']);

Agent_CRM_Sales_Agents_Plugin::instance();

function agent_crm_sales_agents_render_listing(array $params = []): string
{
    return Agent_CRM_Sales_Agents_Plugin::instance()->render_listing($params);
}
