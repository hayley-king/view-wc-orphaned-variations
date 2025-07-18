<?php
/**
 * Plugin Name: WooCommerce Orphaned Variations Viewer
 * Description: Display and manage orphaned product variations in WooCommerce
 * Version: 1.1.0
 * Author: Hayley King
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

class WC_Orphaned_Variations_Viewer {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_delete_orphaned_variations', array($this, 'ajax_delete_orphaned_variations'));
        add_action('wp_ajax_delete_single_variation', array($this, 'ajax_delete_single_variation'));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Orphaned Variations',
            'Orphaned Variations',
            'manage_woocommerce',
            'orphaned-variations',
            array($this, 'admin_page')
        );
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'woocommerce_page_orphaned-variations') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_style('wp-admin');
        
        // Add inline CSS for better styling
        wp_add_inline_style('wp-admin', '
            .orphaned-variations-table { margin-top: 20px; }
            .orphaned-variations-table th { background-color: #f1f1f1; }
            .variation-actions { white-space: nowrap; }
            .delete-single { color: #a00; text-decoration: none; }
            .delete-single:hover { color: #dc3232; }
            .orphaned-count { font-weight: bold; color: #d54e21; }
            .no-orphans { color: #46b450; font-weight: bold; }
            .loading { opacity: 0.6; }
        ');
        
        // Add inline JavaScript
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                // Handle bulk delete
                $("#delete-all-orphaned").click(function(e) {
                    e.preventDefault();
                    if (!confirm("Are you sure you want to delete all orphaned variations? This action cannot be undone.")) {
                        return;
                    }
                    
                    var button = $(this);
                    button.prop("disabled", true).text("Deleting...");
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "delete_orphaned_variations",
                            nonce: "' . wp_create_nonce('delete_orphaned_variations') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                alert("Deleted " + response.data.count + " orphaned variations.");
                                location.reload();
                            } else {
                                alert("Error: " + response.data.message);
                            }
                        },
                        error: function() {
                            alert("An error occurred while deleting variations.");
                        },
                        complete: function() {
                            button.prop("disabled", false).text("Delete All Orphaned Variations");
                        }
                    });
                });
                
                // Handle single delete
                $(".delete-single").click(function(e) {
                    e.preventDefault();
                    if (!confirm("Are you sure you want to delete this variation?")) {
                        return;
                    }
                    
                    var link = $(this);
                    var row = link.closest("tr");
                    var variationId = link.data("variation-id");
                    
                    row.addClass("loading");
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "delete_single_variation",
                            variation_id: variationId,
                            nonce: "' . wp_create_nonce('delete_single_variation') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                row.fadeOut(300, function() {
                                    $(this).remove();
                                    // Update count
                                    var currentCount = parseInt($(".orphaned-count").text());
                                    var newCount = currentCount - 1;
                                    $(".orphaned-count").text(newCount);
                                    
                                    if (newCount === 0) {
                                        location.reload();
                                    }
                                });
                            } else {
                                alert("Error: " + response.data.message);
                                row.removeClass("loading");
                            }
                        },
                        error: function() {
                            alert("An error occurred while deleting the variation.");
                            row.removeClass("loading");
                        }
                    });
                });
            });
        ');
    }
    
    public function get_orphaned_variations() {
        $orphaned_variations = array();
        
        // Get all product variations using WooCommerce data store
        $variation_ids = get_posts(array(
            'post_type' => 'product_variation',
            'post_status' => array('publish', 'private', 'draft', 'pending', 'inherit'),
            'numberposts' => -1,
            'fields' => 'ids'
        ));
        
        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            
            if (!$variation) {
                continue;
            }
            
            $parent_id = $variation->get_parent_id();
            $parent_product = wc_get_product($parent_id);
            
            // Check if parent doesn't exist or is trashed
            if (!$parent_product || $parent_product->get_status() === 'trash') {
                $variation_data = get_post($variation_id);
                if ($variation_data) {
                    $orphaned_variations[] = (object) array(
                        'ID' => $variation_id,
                        'post_title' => $variation_data->post_title,
                        'post_parent' => $parent_id,
                        'post_date' => $variation_data->post_date,
                        'post_status' => $variation_data->post_status
                    );
                }
            }
        }
        
        // Sort by date (newest first)
        usort($orphaned_variations, function($a, $b) {
            return strtotime($b->post_date) - strtotime($a->post_date);
        });
        
        return $orphaned_variations;
    }
    
    public function get_variation_attributes($variation_id) {
        $attributes = array();
        $variation = wc_get_product($variation_id);
        
        if ($variation && $variation->is_type('variation')) {
            $variation_attributes = $variation->get_attributes();
            foreach ($variation_attributes as $attribute_name => $attribute_value) {
                // Get the attribute label from taxonomy if it exists
                $attribute_label = $attribute_name;
                if (strpos($attribute_name, 'attribute_') === 0) {
                    $taxonomy = str_replace('attribute_', '', $attribute_name);
                    if (taxonomy_exists($taxonomy)) {
                        $attribute_object = get_taxonomy($taxonomy);
                        if ($attribute_object) {
                            $attribute_label = $attribute_object->labels->singular_name;
                        }
                    } else {
                        $attribute_label = ucfirst(str_replace(array('attribute_', '_', '-'), array('', ' ', ' '), $attribute_name));
                    }
                }
                
                // Get the term name if it's a taxonomy term
                if (strpos($attribute_name, 'attribute_') === 0) {
                    $taxonomy = str_replace('attribute_', '', $attribute_name);
                    if (taxonomy_exists($taxonomy)) {
                        $term = get_term_by('slug', $attribute_value, $taxonomy);
                        if ($term) {
                            $attribute_value = $term->name;
                        }
                    }
                }
                
                $attributes[] = $attribute_label . ': ' . $attribute_value;
            }
        }
        
        return implode(', ', $attributes);
    }
    
    public function admin_page() {
        $orphaned_variations = $this->get_orphaned_variations();
        $count = count($orphaned_variations);
        
        ?>
        <div class="wrap">
            <h1>Orphaned Product Variations</h1>
            <p>This page shows product variations that no longer have a valid parent product. These variations are considered "orphaned" and can be safely deleted. Use the "Orphaned Variations" tool provided by WooCommerce on <a href="https://hkco.mystagingwebsite.com/wp-admin/admin.php?page=wc-status&tab=tools" target="_blank">WP Admin > WooCommerce > Status > Tools</a>.</p>
            
            <?php if ($count > 0): ?>
                <div class="notice notice-warning">
                    <p><strong>Found <span class="orphaned-count"><?php echo $count; ?></span> orphaned variation(s).</strong></p>
                </div>
                
                <p>
                    <button id="delete-all-orphaned" class="button button-secondary">
                        Delete All Orphaned Variations
                    </button>
                </p>
                
                <table class="wp-list-table widefat fixed striped orphaned-variations-table">
                    <thead>
                        <tr>
                            <th>Variation ID</th>
                            <th>Title</th>
                            <th>Attributes</th>
                            <th>Parent ID</th>
                            <th>Date Created</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orphaned_variations as $variation): ?>
                            <tr>
                                <td><?php echo esc_html($variation->ID); ?></td>
                                <td><?php echo esc_html($variation->post_title); ?></td>
                                <td><?php echo esc_html($this->get_variation_attributes($variation->ID)); ?></td>
                                <td><?php echo esc_html($variation->post_parent); ?></td>
                                <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($variation->post_date))); ?></td>
                                <td><?php echo esc_html($variation->post_status); ?></td>
                                <td class="variation-actions">
                                    <a href="#" class="delete-single" data-variation-id="<?php echo esc_attr($variation->ID); ?>">Delete</a>
                                    |
                                    <a href="<?php echo admin_url('post.php?post=' . $variation->ID . '&action=edit'); ?>" target="_blank">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
            <?php else: ?>
                <div class="notice notice-success">
                    <p><strong class="no-orphans">No orphaned variations found!</strong> All your product variations have valid parent products.</p>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 30px;">
                <h3>What are orphaned variations?</h3>
                <p>Orphaned variations are product variations whose parent products have been deleted or moved to trash. These variations cannot be displayed on your store and serve no purpose, so they can be safely removed to clean up your database.</p>
                
                <h3>Is it safe to delete them?</h3>
                <p>Yes, it's completely safe to delete orphaned variations. They cannot be purchased or displayed since their parent products no longer exist. Deleting them will help optimize your database and reduce clutter.</p>
            </div>
        </div>
        <?php
    }
    
    public function ajax_delete_orphaned_variations() {
        check_ajax_referer('delete_orphaned_variations', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        
        $orphaned_variations = $this->get_orphaned_variations();
        $count = 0;
        
        foreach ($orphaned_variations as $variation) {
            // Use WooCommerce's product deletion method
            $variation_product = wc_get_product($variation->ID);
            if ($variation_product && $variation_product->delete(true)) {
                $count++;
            }
        }
        
        wp_send_json_success(array('count' => $count));
    }
    
    public function ajax_delete_single_variation() {
        check_ajax_referer('delete_single_variation', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        
        $variation_id = intval($_POST['variation_id']);
        
        // Use WooCommerce's product deletion method
        $variation_product = wc_get_product($variation_id);
        if ($variation_product && $variation_product->delete(true)) {
            wp_send_json_success();
        } else {
            wp_send_json_error(array('message' => 'Failed to delete variation'));
        }
    }
}

// Initialize the plugin
new WC_Orphaned_Variations_Viewer();
?>