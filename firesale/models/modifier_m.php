<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Modifier model
 *
 * @author      Jamie Holdroyd
 * @author      Chris Harvey
 * @package     FireSale\Core\Models
 *
 */
class Modifier_m extends MY_Model
{
    private $cache_mods = array();
    private $cache_vars = array();

    public function cart_variation($options)
    {

        // Variables
        $modifiers     = $this->get_modifiers($options['prd_code'][0]);
        $stream        = $this->streams->streams->get_stream('firesale_product_variations', 'firesale_product_variations');
        $post          = $_POST;
        $post['price'] = 0;
        $ids           = array();

        // Loop through options
        foreach ($options['options'] as $mod => $var) {

            // Get variations
            $modifier = $modifiers[$mod];

            // Check type
            if ($modifier['type']['key'] == '1') {
                $variation = $modifier['variations'][$var];
                $ids[] = $var;
            } elseif ($modifier['type']['key'] == '3') {
                $variation      = $modifier['variations'][$var];
                $post['price'] += $variation['price'];
            }

            // Change options
            $post['options'][$mod] = array(
                                        'mod_id' => $modifier['id'],
                                        'var_id' => ( isset($variation) ? $variation['id'] : '' ),
                                        'type'   => $modifier['type']['key'],
                                        'title'  => $modifier['title'],
                                        'value'  => ( isset($variation) ? $variation['title'] : $var )
                                     );

            // Unset before next loop
            unset($modifier);
            unset($variation);
        }

        // Get correct ID for options
        if ( ! empty($ids) ) {
            $post['prd_code'][0] = $this->variation_exists($ids, $stream->id);
        }

        // Retrun
        return $post;
    }

    public function get_modifiers($product)
    {

        // Check cache
        if ( array_key_exists($product, $this->cache_mods) ) {
            return $this->cache_mods[$product];
        }

        // Variables
        $params = array(
                    'stream'    => 'firesale_product_modifiers',
                    'namespace' => 'firesale_product_modifiers',
                    'where'     => "parent = '{$product}'",
                    'order_by'  => 'ordering_count',
                    'sort'      => 'asc'
                  );

        // Get the modifiers
        $modifiers = $this->streams->entries->get_entries($params);
        $tmp = array();

        // Check total
        if ($modifiers['total'] > 0) {

            // Get the variations
            foreach ($modifiers['entries'] as &$modifier) {
                $modifier['variations'] = $this->get_variations($modifier['id']);
                $tmp[$modifier['id']]   = $modifier;
            }

            $modifiers['entries'] = $tmp;

            // Add into cache
            $this->cache_mods[$product] = $modifiers['entries'];

            return $modifiers['entries'];
        }

        // Nothing found
        return array();
    }

    public function get_variations($parent)
    {

        // Check cache
        if ( array_key_exists($parent, $this->cache_vars) ) {
            return $this->cache_vars[$parent];
        }

        // Variables
        $return   = array();
        $currency = ( $this->session->userdata('currency') ? $this->session->userdata('currency') : 1 );
        $currency = $this->pyrocache->model('currency_m', 'get', array($currency), $this->firesale->cache_time);
        $params   = array(
                      'stream'    => 'firesale_product_variations',
                      'namespace' => 'firesale_product_variations',
                      'where'     => "parent = '{$parent}'",
                      'order_by'  => 'ordering_count',
                      'sort'      => 'asc'
                    );

        // Get the variations
        $variations = $this->streams->entries->get_entries($params);
        $tmp = array();

        foreach ($variations['entries'] as &$variation) {

            // Add and format difference
            $before = ( substr($variation['price'], 0, 1) == '-' ? '-' : ( 0 + $variation['price'] > 0 ? '+' : '' ) );
            $price  = str_replace('-', '', $variation['price']);
            $variation['difference'] = $before.$this->currency_m->format_string($price, $currency);

            // Reassign with id as key
            $tmp[$variation['id']] = $variation;
        }

        // Reassign
        $variations['entries'] = $tmp;

        // Check total
        if ($variations['total'] > 0) {
            // Add into cache
            $this->cache_vars[$parent] = $variations['entries'];

            return $variations['entries'];
        }

        // Nothing found
        return array();
    }

    public function edit_variations($row, $input)
    {

        // Variables
        $stream = $this->streams->streams->get_stream('firesale_product_variations', 'firesale_product_variations');
        $tax    = ( 100 + $this->taxes_m->get_percentage() ) / 100;

        // Get all products that are part of this variation
        $query = $this->db->select('firesale_products_id')
                          ->where('row_id', $row->id)
                          ->where('firesale_product_variations_id', $stream->id)
                          ->get('firesale_product_variations_firesale_products');

        // Check for results
        if ( $query->num_rows() ) {

            // Get results
            $variations = $query->result_array();

            // Loop through them
            foreach ($variations as $variation) {

                // Get the product details
                $product = $this->products_m->get_product($variation['firesale_products_id'], null, true);
                $update  = array();

                // Edit price
                $diff = ( $input['price'] - $row->price );
                $update['price']     = round(( $product['price'] + $diff ), 2);
                $update['price_tax'] = round(( $update['price'] / $tax ), 2);

                // Edit title
                $update['title'] = str_replace(' '.$row->title, ' '.$input['title'], $product['title']);

                // Edit code
                $old = strtoupper(substr(str_replace(' ', '', $row->title), 0, 2));
                $new = strtoupper(substr(str_replace(' ', '', $input['title']), 0, 2));
                $update['code'] = str_replace($old, $new, $product['code']);

                // Update the product
                $this->db->where('id', $product['id'])->update('firesale_products', $update);
            }

        }

    }

    public function delete_modifier($id)
    {

        // Get modifier type
        $row = $this->db->select('type')->where('id', $id)->get('firesale_product_modifiers')->row();

        // Check type
        if ($row->type == '1') {

            // Select variations
            $results = $this->db->where('parent', $id)->get('firesale_product_variations')->result_array();

            if ( ! empty($results) ) {
                foreach ($results as $result) {
                    $this->delete_variation($result['id']);
                }
            }

        }

        // Delete this
        return $this->db->where('id', $id)->delete('firesale_product_modifiers');
    }

    public function delete_variation($id)
    {

        // Variables
        $stream = $this->streams->streams->get_stream('firesale_product_variations', 'firesale_product_variations');

        // Delete associated products
        $this->delete_variation_products($id);

        // Delete remaining references
        $this->db->where('row_id', $id)->where('firesale_product_variations_id', $stream->id)->delete('firesale_product_variations_firesale_products');

        // Delete from variations
        return $this->db->where('id', $id)->delete('firesale_product_variations');
    }

    public function delete_variation_products($id)
    {

        // Variables
        $stream = $this->streams->streams->get_stream('firesale_product_variations', 'firesale_product_variations');

        // Get all products that are part of this variation
        $query = $this->db->select('firesale_products_id')
                          ->where('row_id', $id)
                          ->where('firesale_product_variations_id', $stream->id)
                          ->get('firesale_product_variations_firesale_products');

        // Check for results
        if ( $query->num_rows() ) {

            // Get results
            $variations = $query->result_array();

            // Loop through them
            foreach ($variations as $variation) {
                $action = $this->products_m->delete_product($variation['firesale_products_id'], FALSE);
            }

        }

    }

    public function get_products($product)
    {

        // Variables
        $stream     = $this->streams->streams->get_stream('firesale_product_variations', 'firesale_product_variations');
        $modifiers  = $this->get_modifiers($product);
        $variations = $this->possible_variations($modifiers);
        $products   = array();

        // Loop variations
        foreach ($variations AS $variation) {
            // Get products
            if ( $id = $this->variation_exists($variation, $stream->id) ) {
                $products[] = $this->products_m->get_product($id, null, true);
            }
        }

        return $products;
    }

    public function product_variations($product, $is_variation = false)
    {

        // Variables
        $stream = $this->streams->streams->get_stream('firesale_product_variations', 'firesale_product_variations');

        // Check if this is a variation
        // If so we must go bottom up rather than top down
        if ($is_variation) {

            // Get variations
            $query = $this->db->select('m.*, v.title AS var_title, v.price AS var_price, v.id AS var_id')
                              ->from('firesale_product_variations_firesale_products AS fp')
                              ->join('firesale_product_variations AS v', 'v.id = fp.row_id', 'inner')
                              ->join('firesale_product_modifiers AS m', 'm.id = v.parent', 'inner')
                              ->where('fp.firesale_products_id', $product)
                              ->where('fp.firesale_product_variations_id', $stream->id)
                              ->order_by('m.ordering_count', 'asc')
                              ->get();

            // Check results
            if ( $query->num_rows() ) {
                // Get results
                $results = $query->result_array();

                return $query->result_array();
            }

            return array();
        }

        // Otherwise just send back the modifiers
        return $this->get_modifiers($product);
    }

    public function build_variations($product, $stream)
    {

        // Variables
        $modifiers = $this->get_modifiers($product);

        // Build an array of the possible variations for this product
        $variations = $this->possible_variations($modifiers);

        // Loop through possible and determine what does and doesn't exist
        foreach ($variations AS $variation) {

            if ( ! $this->variation_exists($variation, $stream->id) ) {
                // Doesn't exist, create it
                $this->variation_create($product, $variation, $stream->id);
            }

        }

    }

    public function variation_create($product, $variations, $stream_id)
    {

        // Duplicate parent product
        $id = $this->products_m->duplicate_product($product);

        // Variables
        $update  = array();
        $product = $this->products_m->get_product($id);
        $tax     = ( 100 + $this->taxes_m->get_percentage() ) / 100;

        // Update title
        $product['title'] .= ' -';

        // Loop the variations
        foreach ($variations as $variation) {

            // Get the variation details
            $row = $this->db->where('id', $variation)->get('firesale_product_variations')->row();

            // Append the title and code
            $product['title'] .= ' '.$row->title;
            $product['code']  .= strtoupper(substr($row->title, 0, 2));

            // Rebuild the prices
            $product['price'] += $row->price;

            // Add to lookup table
            $lookup = array('row_id' => $variation, 'firesale_product_variations_id' => $stream_id, 'firesale_products_id' => $id);
            $this->db->insert('firesale_product_variations_firesale_products', $lookup);

        }

        // Add to update
        $update['title']        = $product['title'];
        $update['code']         = $product['code'];
        $update['price']        = number_format($product['price'], 2);
        $update['price_tax']    = number_format(( $product['price'] / $tax ), 2);
        $update['is_variation'] = '1';

        // Perform update
        return $this->db->where('id', $id)->update('firesale_products', $update);
    }

    public function variation_exists($variations, $stream_id)
    {

        // Get initial ID
        $id = $variations[0];
        array_shift($variations);

        // Variables
        $sql = "SELECT fp_0.`firesale_products_id`
                FROM `".SITE_REF."_firesale_product_variations_firesale_products` AS `fp_0`";

        if ( ! empty($variations) ) {
            // Loop variations
            foreach ($variations as $key => $variation) {
                $key += 1;
                $sql .= "\nINNER JOIN `default_firesale_product_variations_firesale_products` AS `fp_{$key}` ON ( `fp_{$key}`.`row_id` = {$variation} AND `fp_{$key}`.`firesale_products_id` = fp_0.`firesale_products_id` )";
            }
        }

        // Append where
        $sql .= "\nWHERE fp_0.`row_id` = {$id}\nAND fp_0.`firesale_product_variations_id` = {$stream_id}";

        // Run query
        $query = $this->db->query($sql);

        // Check for results
        if ( $query->num_rows() ) {
            $row = $query->row();

            return $row->firesale_products_id;
        }

        // Not found
        return FALSE;
    }

    public function possible_variations($modifiers)
    {

        // Variables
        $options = array();

        // Pull out all variation information
        foreach ($modifiers as $modifier) {
            if ($modifier['type']['key'] == '1') {
                foreach ($modifier['variations'] as $variation) {
                    if ( ! array_key_exists($modifier['id'], $options) ) {
                        $options[$modifier['id']] = array();
                    }
                    $options[$modifier['id']][] = $variation['id'];
                }
            }
        }

        return $this->array_cartesian_product($options);
    }

    // Stack overflow
    // http://stackoverflow.com/questions/8567082/how-to-generate-in-php-all-combinations-of-items-in-multiple-arrays
    public function array_cartesian_product($arrays)
    {
        $result = array();
        $arrays = array_values($arrays);
        $sizeIn = sizeof($arrays);
        $size = $sizeIn > 0 ? 1 : 0;
        foreach ($arrays as $array)
            $size = $size * sizeof($array);
        for ($i = 0; $i < $size; $i ++) {
            $result[$i] = array();
            for ($j = 0; $j < $sizeIn; $j ++)
                array_push($result[$i], current($arrays[$j]));
            for ($j = ($sizeIn -1); $j >= 0; $j --) {
                if (next($arrays[$j]))
                    break;
                elseif (isset ($arrays[$j]))
                    reset($arrays[$j]);
            }
        }

        return $result;
    }

}
