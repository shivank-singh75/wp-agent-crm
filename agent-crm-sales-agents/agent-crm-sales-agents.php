<?php
/**
 * Plugin Name: Agent CRM Sales Agents
 * Description: Displays sales agents from an Agent CRM backend instance with configurable connection, cache, and display settings.
 * Version: 1.1.0
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
        $this->add_field('endpoint_path', __('Agent Endpoint Path', 'agent-crm-sales-agents'), 'text');
        $this->add_field('tenant_id', __('Instance / Tenant ID', 'agent-crm-sales-agents'), 'number');
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

                printf(
                    '<input type="%s" class="regular-text" name="%s" value="%s"%s>',
                    esc_attr($input_type),
                    esc_attr($name),
                    esc_attr($display_value),
                    $autocomplete
                );
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
            'endpoint_path' => sanitize_text_field($input['endpoint_path'] ?? self::DEFAULT_ENDPOINT_PATH),
            'tenant_id' => absint($input['tenant_id'] ?? 0),
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
            '1.1.0'
        );

        wp_register_script(
            'agent-crm-sales-agents',
            plugins_url('assets/js/agent-crm-sales-agents.js', __FILE__),
            [],
            '1.1.0',
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

    public function render_shortcode(array $atts = []): string
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
            ],
            $atts,
            'agent_crm_sales_agents'
        );

        wp_enqueue_style('agent-crm-sales-agents');

        $agents = $this->fetch_agents(['lead_id' => absint($atts['lead_id'])]);
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
            'pageSize' => 100,
            'pageNumber' => 1,
        ];
        $lead_id = absint($args['lead_id'] ?? $settings['default_lead_id']);

        if ($lead_id > 0) {
            $request_body['leadId'] = $lead_id;
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

    private function normalize_agent(array $agent): array
    {
        $first = trim((string) ($agent['firstName'] ?? ''));
        $last = trim((string) ($agent['lastName'] ?? ''));
        $name = trim($first . ' ' . $last);

        if ($name === '') {
            $name = !empty($agent['email']) ? (string) $agent['email'] : __('Sales agent', 'agent-crm-sales-agents');
        }

        $phone = trim((string) ($agent['phone'] ?? ''));
        $phone_code = trim((string) ($agent['phoneCode'] ?? ''));

        if ($phone !== '' && $phone_code !== '' && !$this->starts_with($phone, '+')) {
            $phone = '+' . preg_replace('/\D+/', '', $phone_code) . preg_replace('/\D+/', '', $phone);
        }

        return [
            'name' => $name,
            'initials' => strtoupper(substr($first ?: $name, 0, 1) . substr($last, 0, 1)),
            'email' => sanitize_email($agent['email'] ?? ''),
            'phone' => $phone,
            'profilePicture' => esc_url_raw($agent['profilePicture'] ?? ''),
            'distance' => isset($agent['distance']) ? (string) $agent['distance'] : '',
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

        if ($settings['endpoint_path'] === self::LEGACY_ENDPOINT_PATH) {
            $settings['endpoint_path'] = self::DEFAULT_ENDPOINT_PATH;
        }

        return $settings;
    }

    private static function default_settings(): array
    {
        return [
            'base_url' => '',
            'endpoint_path' => self::DEFAULT_ENDPOINT_PATH,
            'tenant_id' => 1,
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
