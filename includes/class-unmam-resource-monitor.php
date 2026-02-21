<?php
/**
 * Resource Monitor for Media Usage Inspector
 *
 * Handles adaptive resource management to prevent server overload
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resource Monitor class
 */
class UNMAM_Resource_Monitor {

    /**
     * Single instance
     *
     * @var UNMAM_Resource_Monitor|null
     */
    private static $instance = null;

    /**
     * Resource mode constants
     */
    const MODE_AUTO = 'auto';
    const MODE_LOW  = 'low';
    const MODE_HIGH = 'high';

    /**
     * Batch size limits by mode
     */
    const BATCH_SIZES = array(
        'low'  => array( 'min' => 10, 'max' => 25, 'default' => 15 ),
        'auto' => array( 'min' => 20, 'max' => 100, 'default' => 50 ),
        'high' => array( 'min' => 50, 'max' => 200, 'default' => 100 ),
    );

    /**
     * Memory thresholds (percentage of limit)
     */
    const MEMORY_WARNING_THRESHOLD  = 70; // Start reducing batch size
    const MEMORY_CRITICAL_THRESHOLD = 85; // Pause and wait
    const MEMORY_SAFE_THRESHOLD     = 50; // Can increase batch size

    /**
     * Time limits (seconds)
     */
    const MAX_EXECUTION_TIME_BUFFER = 10; // Leave 10s buffer before timeout
    const MIN_TIME_PER_BATCH        = 2;  // Minimum seconds per batch
    const MAX_TIME_PER_BATCH        = 30; // Maximum seconds per batch

    /**
     * Delay between batches (milliseconds) by mode
     */
    const BATCH_DELAYS = array(
        'low'  => 1000, // 1 second
        'auto' => 300,  // 300ms
        'high' => 50,   // 50ms
    );

    /**
     * Current batch size
     *
     * @var int
     */
    private $current_batch_size;

    /**
     * Start time of current operation
     *
     * @var float
     */
    private $start_time;

    /**
     * Memory at start
     *
     * @var int
     */
    private $start_memory;

    /**
     * Performance history for adaptive sizing
     *
     * @var array
     */
    private $performance_history = array();

    /**
     * Get singleton instance
     *
     * @return UNMAM_Resource_Monitor
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_performance_history();
    }

    /**
     * Get current resource mode
     *
     * @return string
     */
    public function get_mode() {
        $settings = Unattached_Media_Manager::get_setting();
        return isset( $settings['resource_mode'] ) ? $settings['resource_mode'] : self::MODE_AUTO;
    }

    /**
     * Get recommended batch size based on current resources
     *
     * @return int
     */
    public function get_recommended_batch_size() {
        $mode   = $this->get_mode();
        $limits = self::BATCH_SIZES[ $mode ];

        if ( self::MODE_AUTO !== $mode ) {
            // Fixed mode - return default for that mode
            return $limits['default'];
        }

        // Auto mode - calculate based on resources
        $memory_factor = $this->get_memory_factor();
        $time_factor   = $this->get_time_factor();
        $history_factor = $this->get_history_factor();

        // Combined factor (0.0 to 1.0)
        $factor = min( $memory_factor, $time_factor, $history_factor );

        // Calculate batch size
        $range      = $limits['max'] - $limits['min'];
        $batch_size = $limits['min'] + (int) ( $range * $factor );

        // Round to nearest 5 for cleaner numbers
        $batch_size = (int) round( $batch_size / 5 ) * 5;

        $this->current_batch_size = max( $limits['min'], min( $limits['max'], $batch_size ) );

        return $this->current_batch_size;
    }

    /**
     * Get memory usage factor (0.0 = low resources, 1.0 = plenty of resources)
     *
     * @return float
     */
    private function get_memory_factor() {
        $memory_limit = $this->get_memory_limit();
        $memory_used  = memory_get_usage( true );

        if ( $memory_limit <= 0 ) {
            // Can't determine limit, be conservative
            return 0.5;
        }

        $usage_percent = ( $memory_used / $memory_limit ) * 100;

        if ( $usage_percent >= self::MEMORY_CRITICAL_THRESHOLD ) {
            return 0.1; // Very low - critical
        } elseif ( $usage_percent >= self::MEMORY_WARNING_THRESHOLD ) {
            // Linear decrease from warning to critical
            $range = self::MEMORY_CRITICAL_THRESHOLD - self::MEMORY_WARNING_THRESHOLD;
            $position = $usage_percent - self::MEMORY_WARNING_THRESHOLD;
            return 0.5 - ( 0.4 * ( $position / $range ) );
        } elseif ( $usage_percent <= self::MEMORY_SAFE_THRESHOLD ) {
            return 1.0; // Plenty of memory
        } else {
            // Between safe and warning
            $range = self::MEMORY_WARNING_THRESHOLD - self::MEMORY_SAFE_THRESHOLD;
            $position = $usage_percent - self::MEMORY_SAFE_THRESHOLD;
            return 1.0 - ( 0.5 * ( $position / $range ) );
        }
    }

    /**
     * Get time factor based on remaining execution time
     *
     * @return float
     */
    private function get_time_factor() {
        $max_time = $this->get_max_execution_time();

        if ( $max_time <= 0 ) {
            // No limit set, assume moderate resources
            return 0.7;
        }

        $elapsed = $this->start_time ? ( microtime( true ) - $this->start_time ) : 0;
        $remaining = $max_time - $elapsed - self::MAX_EXECUTION_TIME_BUFFER;

        if ( $remaining <= self::MIN_TIME_PER_BATCH ) {
            return 0.1; // Almost out of time
        } elseif ( $remaining >= self::MAX_TIME_PER_BATCH * 3 ) {
            return 1.0; // Plenty of time
        } else {
            // Scale based on remaining time
            return min( 1.0, $remaining / ( self::MAX_TIME_PER_BATCH * 2 ) );
        }
    }

    /**
     * Get history factor based on previous batch performance
     *
     * @return float
     */
    private function get_history_factor() {
        if ( empty( $this->performance_history ) ) {
            return 0.7; // No history, be moderate
        }

        // Look at last 5 batches
        $recent = array_slice( $this->performance_history, -5 );

        $avg_time_per_item = 0;
        $count = 0;

        foreach ( $recent as $record ) {
            if ( $record['items'] > 0 && $record['time'] > 0 ) {
                $avg_time_per_item += $record['time'] / $record['items'];
                $count++;
            }
        }

        if ( $count === 0 ) {
            return 0.7;
        }

        $avg_time_per_item /= $count;

        // Target: process items in under 0.1 seconds each
        if ( $avg_time_per_item <= 0.05 ) {
            return 1.0; // Very fast
        } elseif ( $avg_time_per_item >= 0.2 ) {
            return 0.3; // Slow
        } else {
            // Linear scale
            return 1.0 - ( ( $avg_time_per_item - 0.05 ) / 0.15 ) * 0.7;
        }
    }

    /**
     * Get PHP memory limit in bytes
     *
     * @return int
     */
    public function get_memory_limit() {
        $limit = ini_get( 'memory_limit' );

        if ( '-1' === $limit ) {
            return -1; // Unlimited
        }

        return $this->convert_to_bytes( $limit );
    }

    /**
     * Get max execution time in seconds
     *
     * @return int
     */
    public function get_max_execution_time() {
        $time = (int) ini_get( 'max_execution_time' );
        return $time > 0 ? $time : 30; // Default to 30 if not set
    }

    /**
     * Convert PHP ini value to bytes
     *
     * @param string $value PHP ini value.
     * @return int
     */
    private function convert_to_bytes( $value ) {
        $value = trim( $value );
        $last  = strtolower( $value[ strlen( $value ) - 1 ] );
        $value = (int) $value;

        switch ( $last ) {
            case 'g':
                $value *= 1024;
                // Fall through
            case 'm':
                $value *= 1024;
                // Fall through
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Start monitoring a batch operation
     */
    public function start_batch() {
        $this->start_time   = microtime( true );
        $this->start_memory = memory_get_usage( true );
    }

    /**
     * End batch monitoring and record performance
     *
     * @param int $items_processed Number of items processed.
     */
    public function end_batch( $items_processed ) {
        $end_time   = microtime( true );
        $end_memory = memory_get_usage( true );

        $record = array(
            'time'        => $end_time - $this->start_time,
            'memory_used' => $end_memory - $this->start_memory,
            'items'       => $items_processed,
            'batch_size'  => $this->current_batch_size,
            'timestamp'   => time(),
        );

        $this->performance_history[] = $record;

        // Keep only last 20 records
        if ( count( $this->performance_history ) > 20 ) {
            $this->performance_history = array_slice( $this->performance_history, -20 );
        }

        $this->save_performance_history();

        // Free up memory
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }

        // Clear any pending output buffers
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }
    }

    /**
     * Check if we should pause due to resource constraints
     *
     * @return bool
     */
    public function should_pause() {
        $memory_limit = $this->get_memory_limit();
        $memory_used  = memory_get_usage( true );

        if ( $memory_limit > 0 ) {
            $usage_percent = ( $memory_used / $memory_limit ) * 100;
            if ( $usage_percent >= self::MEMORY_CRITICAL_THRESHOLD ) {
                return true;
            }
        }

        // Check time remaining
        $max_time = $this->get_max_execution_time();
        if ( $max_time > 0 && $this->start_time ) {
            $elapsed = microtime( true ) - $this->start_time;
            if ( $elapsed >= ( $max_time - self::MAX_EXECUTION_TIME_BUFFER ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get delay between batches in milliseconds
     *
     * @return int
     */
    public function get_batch_delay() {
        $mode = $this->get_mode();
        return self::BATCH_DELAYS[ $mode ];
    }

    /**
     * Get current resource status
     *
     * @return array
     */
    public function get_status() {
        $memory_limit = $this->get_memory_limit();
        $memory_used  = memory_get_usage( true );
        $memory_peak  = memory_get_peak_usage( true );

        return array(
            'mode'              => $this->get_mode(),
            'memory_limit'      => $this->format_bytes( $memory_limit ),
            'memory_used'       => $this->format_bytes( $memory_used ),
            'memory_peak'       => $this->format_bytes( $memory_peak ),
            'memory_percent'    => $memory_limit > 0 ? round( ( $memory_used / $memory_limit ) * 100, 1 ) : 0,
            'max_execution'     => $this->get_max_execution_time(),
            'recommended_batch' => $this->get_recommended_batch_size(),
            'batch_delay'       => $this->get_batch_delay(),
            'performance'       => $this->get_performance_summary(),
        );
    }

    /**
     * Get performance summary
     *
     * @return array
     */
    private function get_performance_summary() {
        if ( empty( $this->performance_history ) ) {
            return array(
                'avg_time_per_item'   => 0,
                'avg_memory_per_item' => 0,
                'total_batches'       => 0,
            );
        }

        $total_time   = 0;
        $total_memory = 0;
        $total_items  = 0;

        foreach ( $this->performance_history as $record ) {
            $total_time   += $record['time'];
            $total_memory += $record['memory_used'];
            $total_items  += $record['items'];
        }

        return array(
            'avg_time_per_item'   => $total_items > 0 ? round( $total_time / $total_items, 4 ) : 0,
            'avg_memory_per_item' => $total_items > 0 ? $this->format_bytes( $total_memory / $total_items ) : '0 B',
            'total_batches'       => count( $this->performance_history ),
        );
    }

    /**
     * Format bytes to human readable
     *
     * @param int $bytes Bytes.
     * @return string
     */
    public function format_bytes( $bytes ) {
        if ( $bytes < 0 ) {
            return 'Unlimited';
        }

        $units = array( 'B', 'KB', 'MB', 'GB' );
        $i = 0;

        while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
            $bytes /= 1024;
            $i++;
        }

        return round( $bytes, 2 ) . ' ' . $units[ $i ];
    }

    /**
     * Load performance history from options
     */
    private function load_performance_history() {
        $history = get_option( 'unmam_performance_history', array() );
        $this->performance_history = is_array( $history ) ? $history : array();
    }

    /**
     * Save performance history to options
     */
    private function save_performance_history() {
        update_option( 'unmam_performance_history', $this->performance_history, false );
    }

    /**
     * Clear performance history
     */
    public function clear_history() {
        $this->performance_history = array();
        delete_option( 'unmam_performance_history' );
    }

    /**
     * Get server info for diagnostics
     *
     * @return array
     */
    public function get_server_info() {
        global $wpdb;

        return array(
            'php_version'        => PHP_VERSION,
            'memory_limit'       => $this->format_bytes( $this->get_memory_limit() ),
            'max_execution_time' => $this->get_max_execution_time() . 's',
            'wp_memory_limit'    => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'Not set',
            'wp_max_memory'      => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : 'Not set',
            'mysql_version'      => $wpdb->db_version(),
            'is_multisite'       => is_multisite() ? 'Yes' : 'No',
        );
    }
}
