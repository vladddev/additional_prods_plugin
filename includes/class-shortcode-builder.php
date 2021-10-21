<?php

/**
 * Test_Plugin_Shortcode_Builder
 */
class Test_Plugin_Shortcode_Builder {
    private array $exclude = [];
    public int $columns_count = 5;
    public int $products_count = 5;

    public function __construct() {
        
    }
    
    /**
     * build
     *
     * @return void
     */
    public function build(): void {
        add_shortcode( 'test-plugin', [ $this, 'shortcode' ] );
    }
    
       
    /**
     * shortcode
     *
     * @param  mixed $atts
     * @param  mixed $content
     * @return string
     */
    public function shortcode($atts, string $content): string {
        if (!is_cart()) {
            return 'Только для страницы с корзиной!';
        }

        global $woocommerce_loop, $woocommerce;

        $incart_ids = $this->get_incart_products_ids();
        $this->exclude = $incart_ids;
        $orders_with_actual_items_ids = $this->get_orders_with_items($incart_ids);
        $additional_items_ids = $this->get_additionals_items($orders_with_actual_items_ids);

        if (count($additional_items_ids) < $this->products_count) {
            $top_sales = $this->get_top_sales($this->products_count - count($additional_items_ids));
            $additional_items_ids = array_merge($additional_items_ids, $top_sales);
        }

        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'post__in' => $additional_items_ids
        );
    
        ob_start();
    
        $products = new WP_Query( $args );
        $woocommerce_loop['columns'] = $this->columns_count;
    
        if ( $products->have_posts() ) : ?>
    
            <?php woocommerce_product_loop_start(); ?>
    
                <?php while ( $products->have_posts() ) : $products->the_post(); ?>
    
                    <?php woocommerce_get_template_part( 'content', 'product' ); ?>
    
                <?php endwhile; // end of the loop. ?>
    
            <?php woocommerce_product_loop_end(); ?>
            
        <?php 
        wp_reset_postdata();
        endif;
    
        return $this->wrap_output(ob_get_clean());
    }
    
    /**
     * get_incart_products_ids
     *
     * @return array
     */
    private function get_incart_products_ids(): array
    {
        global $woocommerce;
        $items = $woocommerce->cart->get_cart();
        $products = [];

        foreach($items as $item => $values) { 
        	$products[] = $values['data']->get_id(); 
        }

        return $products;
    }
    
    /**
     * get_orders_with_items
     *
     * @return array
     */
    private function get_orders_with_items(array $incart_ids): array
    {
        global $wpdb;
        $order_ids = [];
        $prods_ids_str = implode(", ", $incart_ids);
        $query = "
            SELECT order_items.`order_id`
            FROM {$wpdb->prefix}woocommerce_order_items as order_items
            INNER JOIN (
                SELECT wp_woocommerce_order_items.`order_id` FROM wp_woocommerce_order_items GROUP BY wp_woocommerce_order_items.`order_id` HAVING COUNT(*) > 1
            ) AS order_items2 ON order_items.`order_id` = order_items2.`order_id`
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
            LEFT JOIN {$wpdb->posts} AS posts ON order_items.order_id = posts.ID
            WHERE posts.post_type = 'shop_order'
            AND order_items.order_item_type = 'line_item'
            AND order_item_meta.meta_key = '_product_id'
            AND order_item_meta.meta_value IN ({$prods_ids_str})
        ";

        $order_ids = $wpdb->get_col($query);

        return $order_ids;
    }
    
    /**
     * get_additionals_items
     *
     * @param  mixed $orders_ids
     * @return array
     */
    private function get_additionals_items(array $orders_ids): array
    {
        if (empty($orders_ids)) {
            return [];
        }

        global $wpdb;
        $items = [];
        $order_ids_str = implode(', ', $orders_ids);
        $incart_prods_ids = implode(', ', $this->exclude);
        $query = "
            SELECT order_item_meta.meta_value
            FROM {$wpdb->prefix}woocommerce_order_items as order_items
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
            LEFT JOIN {$wpdb->posts} AS posts ON order_items.order_id = posts.ID
            WHERE posts.post_type = 'shop_order'
            AND order_items.order_item_type = 'line_item'
            AND order_items.order_id IN ({$order_ids_str})
            AND order_item_meta.meta_key = '_product_id'
            ";

        if (!empty($incart_prods_ids)) {
            $query .= " AND order_item_meta.meta_value NOT IN ({$incart_prods_ids})";
        }
        $query .= " ORDER BY RAND() LIMIT " . $this->products_count;
        
        $items = $wpdb->get_col($query);
        $this->add_items_objects_to_exclude($items);

        return $items;
    }
    
    /**
     * add_items_objects_to_exclude
     *
     * @param  mixed $items
     * @return void
     */
    private function add_items_objects_to_exclude(array $items): void
    {
        foreach ($items as $item) {
            $this->exclude[] = $item;
        }
    }
    
    /**
     * wrap_output
     *
     * @param  mixed $output
     * @return string
     */
    private function wrap_output(string $output): string
    {
        $before = '<div class="woocommerce" style="margin-top: 6rem">';
        $after = '</div>';
        return $before . $output . $after;
    }
    
    /**
     * get_top_sales
     *
     * @param  mixed $count
     * @return array
     */
    private function get_top_sales(int $count): array
    {
        global $wpdb;
        $query = "
            SELECT post_id FROM {$wpdb->posts} AS posts
            LEFT JOIN {$wpdb->postmeta} AS meta ON meta.post_id=posts.ID
            WHERE posts.post_type='product'
            AND posts.post_status='publish'
            AND meta.meta_key='total_sales'
        ";
        if (!empty($this->exclude)) {
            $existing_products = implode(', ', $this->exclude);
            $query .= " AND posts.ID NOT IN ({$existing_products})";
        }
        $query .= " ORDER BY meta.meta_value * 1 DESC LIMIT {$count}";
        $result = $wpdb->get_col($query);
        return $result;
    }
}