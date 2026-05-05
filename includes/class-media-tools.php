<?php

class SEOPC_Media_Tools {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_submenu']);
        add_filter('wp_get_attachment_image_attributes', [$this, 'filter_attachment_attributes'], 20, 3);
        add_filter('the_content', [$this, 'filter_content_images'], 20);
    }

    public function register_submenu() {
        add_submenu_page(
            null,
            __('Media Tools', 'seo-performance-checker'),
            __('Media Tools', 'seo-performance-checker'),
            'manage_options',
            'seo-media-tools',
            [$this, 'render_admin_page']
        );
    }

    private function normalize_text($text) {
        $text = wp_strip_all_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }

    private function is_empty_value($value) {
        $value = $this->normalize_text($value);
        return ($value === '' || $value === '/');
    }

    private function is_hash_like($text) {
        $text = strtolower($this->normalize_text($text));

        if ($text === '') {
            return false;
        }

        if (preg_match('/^[a-f0-9]{16,}$/', $text)) {
            return true;
        }

        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $text)) {
            return true;
        }

        if (preg_match('/^[0-9\-_]{8,}$/', $text)) {
            return true;
        }

        return false;
    }

    private function clean_candidate_text($text) {
        $text = $this->normalize_text($text);

        if ($text === '') {
            return '';
        }

        $text = preg_replace('/^img[\-_ ]+/i', '', $text);
        $text = preg_replace('/^image[\-_ ]+/i', '', $text);
        $text = preg_replace('/-\d+x\d+$/', '', $text);
        $text = preg_replace('/[_\-]+/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }

    private function is_meaningful_text($text) {
        $text = $this->clean_candidate_text($text);

        if ($text === '' || $text === '/') {
            return false;
        }

        $bad = [
            'image', 'img', 'photo', 'picture', 'graphic', 'screenshot',
            'untitled', 'attachment', 'thumbnail', 'medium', 'large', 'full size'
        ];

        if (in_array(strtolower($text), $bad, true)) {
            return false;
        }

        if ($this->is_hash_like($text)) {
            return false;
        }

        return true;
    }

    private function attachment_file_basename($attachment_id) {
        $file = get_attached_file($attachment_id);
        if (!$file) {
            return '';
        }

        $basename = wp_basename($file);
        $basename = preg_replace('/\.(jpe?g|png|gif|svg|webp|avif)$/i', '', $basename);
        return (string) $basename;
    }

    private function filename_to_text($attachment_id) {
        $filename = $this->attachment_file_basename($attachment_id);
        if ($filename === '') {
            return '';
        }

        $filename = preg_replace('/-\d+x\d+$/', '', $filename);
        $filename = preg_replace('/\b\d+\b/', ' ', $filename);
        $filename = preg_replace('/[_\-]+/', ' ', $filename);
        $filename = preg_replace('/\s+/', ' ', $filename);
        $filename = trim((string) $filename);

        if ($filename === '') {
            return '';
        }

        $condensed = str_replace(' ', '', strtolower($filename));
        if ($this->is_hash_like($condensed)) {
            return '';
        }

        return $filename;
    }

    private function is_logo_like($attachment_id) {
        $file = strtolower($this->attachment_file_basename($attachment_id));
        return strpos($file, 'logo') !== false;
    }

    private function is_decorative_like($attachment_id) {
        $file = strtolower($this->attachment_file_basename($attachment_id));
        $keywords = ['divider','pattern','background','bg','spacer','placeholder','decorative','decoration','shape','arrow','bullet','separator','icon'];
        foreach ($keywords as $keyword) {
            if (strpos($file, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    private function readable_brand_from_logo($attachment_id) {
        $text = $this->filename_to_text($attachment_id);
        if ($text === '') {
            $site_name = $this->normalize_text(get_bloginfo('name'));
            return $site_name ? $site_name . ' logo' : 'Site logo';
        }

        $text = preg_replace('/\blogo\b/i', '', $text);
        $text = preg_replace('/\bregistered\b/i', '', $text);
        $text = preg_replace('/\bwhite\b/i', '', $text);
        $text = preg_replace('/\bdark\b/i', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string) $text);

        if ($text === '') {
            $site_name = $this->normalize_text(get_bloginfo('name'));
            return $site_name ? $site_name . ' logo' : 'Site logo';
        }

        return ucwords($text) . ' logo';
    }

    private function build_fallback_alt($attachment_id) {
        $saved_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $saved_alt = $this->clean_candidate_text($saved_alt);
        if ($this->is_meaningful_text($saved_alt)) {
            return $saved_alt;
        }

        $title = get_the_title($attachment_id);
        $title = $this->clean_candidate_text($title);
        if ($this->is_meaningful_text($title)) {
            return $title;
        }

        if ($this->is_logo_like($attachment_id)) {
            return $this->readable_brand_from_logo($attachment_id);
        }

        $filename_text = $this->clean_candidate_text($this->filename_to_text($attachment_id));
        if ($this->is_meaningful_text($filename_text)) {
            return ucwords($filename_text);
        }

        return '';
    }

    private function fill_dimensions($attr, $attachment_id, $size) {
        $missing_width  = empty($attr['width']);
        $missing_height = empty($attr['height']);

        if (!$missing_width && !$missing_height) {
            return $attr;
        }

        $image = wp_get_attachment_image_src($attachment_id, $size);
        if (is_array($image) && !empty($image[1]) && !empty($image[2])) {
            if ($missing_width) {
                $attr['width'] = (int) $image[1];
            }
            if ($missing_height) {
                $attr['height'] = (int) $image[2];
            }
            return $attr;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (is_array($meta)) {
            if ($missing_width && !empty($meta['width'])) {
                $attr['width'] = (int) $meta['width'];
            }
            if ($missing_height && !empty($meta['height'])) {
                $attr['height'] = (int) $meta['height'];
            }
        }

        return $attr;
    }

    public function filter_attachment_attributes($attr, $attachment, $size) {
        if (!is_object($attachment) || empty($attachment->ID)) {
            return $attr;
        }

        $attachment_id = (int) $attachment->ID;
        $attr = $this->fill_dimensions($attr, $attachment_id, $size);

        if (empty($attr['loading'])) {
            $class = isset($attr['class']) ? strtolower((string) $attr['class']) : '';
            $is_priority = !empty($attr['fetchpriority']) && strtolower((string) $attr['fetchpriority']) === 'high';
            $is_logo = $this->is_logo_like($attachment_id);
            $is_heroish = preg_match('/\b(hero|banner|above-the-fold|custom-logo)\b/i', $class);
            if (!$is_priority && !$is_logo && !$is_heroish) {
                $attr['loading'] = 'lazy';
            }
        }

        $current_alt = isset($attr['alt']) ? $this->normalize_text($attr['alt']) : '';
        if (!$this->is_empty_value($current_alt) && $this->is_meaningful_text($current_alt)) {
            $attr['alt'] = $this->clean_candidate_text($current_alt);
            return $attr;
        }

        if ($this->is_decorative_like($attachment_id) && !$this->is_logo_like($attachment_id)) {
            $attr['alt'] = '';
            return $attr;
        }

        $fallback_alt = $this->build_fallback_alt($attachment_id);
        $attr['alt'] = $fallback_alt !== '' ? $fallback_alt : '';
        return $attr;
    }

    public function filter_content_images($content) {
        if (!is_string($content) || stripos($content, '<img') === false) {
            return $content;
        }

        return preg_replace_callback('/<img\b[^>]*>/i', function($matches) {
            $img_tag = $matches[0];
            $fixed = $this->fix_single_img_tag($img_tag);
            return !empty($fixed['updated']) ? $fixed['updated'] : $img_tag;
        }, $content);
    }

    private function get_attachment_id_from_img_tag($img_tag) {
        if (preg_match('/\bwp-image-(\d+)\b/i', $img_tag, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/\bsrc\s*=\s*("|\')([^"\']+)\1/i', $img_tag, $m)) {
            $src = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            return (int) attachment_url_to_postid($src);
        }

        return 0;
    }

    private function get_src_from_img_tag($img_tag) {
        if (preg_match('/\bsrc\s*=\s*("|\')([^"\']+)\1/i', $img_tag, $m)) {
            return html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        }
        return '';
    }

    private function src_filename_fallback_alt($src) {
        if (!$src) {
            return '';
        }

        $path = parse_url($src, PHP_URL_PATH);
        if (!$path) {
            $path = $src;
        }

        $file = wp_basename($path);
        $file = preg_replace('/\.(jpe?g|png|gif|svg|webp|avif)$/i', '', $file);
        $file = preg_replace('/-\d+x\d+$/', '', $file);
        $file = preg_replace('/\b\d+\b/', ' ', $file);
        $file = preg_replace('/[_\-]+/', ' ', $file);
        $file = preg_replace('/\s+/', ' ', $file);
        $file = trim((string) $file);

        if ($file === '' || $this->is_hash_like(str_replace(' ', '', strtolower($file)))) {
            return '';
        }

        return ucwords($file);
    }

    private function get_svg_dimensions_from_file($file) {
        if (!$file || !file_exists($file)) {
            return [0, 0];
        }

        $svg = @file_get_contents($file);
        if ($svg === false || trim($svg) === '') {
            return [0, 0];
        }

        $width = 0;
        $height = 0;

        if (preg_match('/\bwidth\s*=\s*["\']([0-9.]+)(px)?["\']/i', $svg, $m)) {
            $width = (int) round((float) $m[1]);
        }

        if (preg_match('/\bheight\s*=\s*["\']([0-9.]+)(px)?["\']/i', $svg, $m)) {
            $height = (int) round((float) $m[1]);
        }

        if ((!$width || !$height) && preg_match('/\bviewBox\s*=\s*["\']\s*[-0-9.]+\s+[-0-9.]+\s+([0-9.]+)\s+([0-9.]+)\s*["\']/i', $svg, $m)) {
            if (!$width) {
                $width = (int) round((float) $m[1]);
            }
            if (!$height) {
                $height = (int) round((float) $m[2]);
            }
        }

        return [$width, $height];
    }

    private function get_img_dimensions($attachment_id, $src = '') {
        if ($attachment_id > 0) {
            $meta = wp_get_attachment_metadata($attachment_id);
            if (!empty($meta['width']) && !empty($meta['height'])) {
                return [(int) $meta['width'], (int) $meta['height']];
            }

            $file = get_attached_file($attachment_id);
            if ($file && file_exists($file)) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if ($ext === 'svg') {
                    list($svg_width, $svg_height) = $this->get_svg_dimensions_from_file($file);
                    if ($svg_width > 0 && $svg_height > 0) {
                        return [$svg_width, $svg_height];
                    }
                }

                $size = @getimagesize($file);
                if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                    return [(int) $size[0], (int) $size[1]];
                }
            }
        }

        if ($src) {
            $uploads = wp_get_upload_dir();
            if (!empty($uploads['baseurl']) && !empty($uploads['basedir']) && strpos($src, $uploads['baseurl']) === 0) {
                $file = str_replace($uploads['baseurl'], $uploads['basedir'], $src);
                $file = wp_normalize_path($file);
                if (file_exists($file)) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if ($ext === 'svg') {
                        list($svg_width, $svg_height) = $this->get_svg_dimensions_from_file($file);
                        if ($svg_width > 0 && $svg_height > 0) {
                            return [$svg_width, $svg_height];
                        }
                    }

                    $size = @getimagesize($file);
                    if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                        return [(int) $size[0], (int) $size[1]];
                    }
                }
            }
        }

        return [0, 0];
    }

    private function get_alt_for_img($attachment_id, $src = '') {
        if ($attachment_id > 0) {
            if ($this->is_decorative_like($attachment_id) && !$this->is_logo_like($attachment_id)) {
                return '';
            }
            $alt = $this->build_fallback_alt($attachment_id);
            if ($alt !== '') {
                return $alt;
            }
        }

        return $this->src_filename_fallback_alt($src);
    }

    private function fix_single_img_tag($img_tag) {
        $original = $img_tag;
        $changed = false;
        $attachment_id = $this->get_attachment_id_from_img_tag($img_tag);
        $src = $this->get_src_from_img_tag($img_tag);

        $has_alt = preg_match('/\balt\s*=\s*("|\')([^"\']*)\1/i', $img_tag, $alt_match);
        $has_width = preg_match('/\bwidth\s*=\s*("|\')?\d+("|\')?/i', $img_tag);
        $has_height = preg_match('/\bheight\s*=\s*("|\')?\d+("|\')?/i', $img_tag);
        $has_loading = preg_match('/\bloading\s*=\s*("|\')[^"\']+\1/i', $img_tag);
        $alt_value = $has_alt ? $this->normalize_text($alt_match[2]) : '';

        if (!$has_alt || $this->is_empty_value($alt_value)) {
            $alt = $this->get_alt_for_img($attachment_id, $src);
            if ($has_alt) {
                $img_tag = preg_replace('/\balt\s*=\s*("|\')[^"\']*\1/i', 'alt="' . esc_attr($alt) . '"', $img_tag, 1);
            } else {
                $img_tag = preg_replace('/<img\b/i', '<img alt="' . esc_attr($alt) . '"', $img_tag, 1);
            }
            $changed = true;
        }

        if (!$has_width || !$has_height) {
            list($width, $height) = $this->get_img_dimensions($attachment_id, $src);
            if ($width > 0 && !$has_width) {
                $img_tag = preg_replace('/<img\b/i', '<img width="' . (int) $width . '"', $img_tag, 1);
                $changed = true;
            }
            if ($height > 0 && !$has_height) {
                $img_tag = preg_replace('/<img\b/i', '<img height="' . (int) $height . '"', $img_tag, 1);
                $changed = true;
            }
        }

        if (!$has_loading && $attachment_id > 0 && !$this->is_logo_like($attachment_id)) {
            $img_tag = preg_replace('/<img\b/i', '<img loading="lazy"', $img_tag, 1);
            $changed = true;
        }

        return [
            'original' => $original,
            'updated' => $img_tag,
            'changed' => $changed,
            'attachment_id' => $attachment_id,
            'src' => $src,
        ];
    }

    private function maybe_update_alt_text($attachment_id) {
        $current_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if ($this->is_empty_value($current_alt) && !$this->is_decorative_like($attachment_id)) {
            $fallback_alt = $this->build_fallback_alt($attachment_id);
            if ($fallback_alt !== '') {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $fallback_alt);
                return true;
            }
        }
        return false;
    }

    private function optimize_attachment($attachment_id, $max_dimension = 1920, $convert_to_avif = false, $replace_original = false) {
        $result = [
            'attachment_id' => (int) $attachment_id,
            'file' => '',
            'alt_updated' => false,
            'resized_original' => false,
            'metadata_updated' => false,
            'avif_created' => false,
            'replaced_with_avif' => false,
            'avif_url' => '',
            'skipped' => false,
            'errors' => [],
        ];

        $mime = get_post_mime_type($attachment_id);
        if (strpos((string) $mime, 'image/') !== 0) {
            $result['skipped'] = true;
            $result['errors'][] = 'Not an image attachment';
            return $result;
        }

        $file = get_attached_file($attachment_id);
        $result['file'] = $file;
        if (!$file || !file_exists($file)) {
            $result['errors'][] = 'Missing file';
            return $result;
        }

        $result['alt_updated'] = $this->maybe_update_alt_text($attachment_id);

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $size = @getimagesize($file);
        if (($ext === 'svg') || !is_array($size) || empty($size[0]) || empty($size[1])) {
            list($svg_width, $svg_height) = $this->get_svg_dimensions_from_file($file);
            if ($svg_width > 0 && $svg_height > 0) {
                $size = [$svg_width, $svg_height];
            }
        }

        $needs_resize = false;
        if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
            if ((int) $size[0] > $max_dimension || (int) $size[1] > $max_dimension) {
                $needs_resize = true;
            }
        }

        if ($needs_resize && $ext !== 'svg') {
            $editor = wp_get_image_editor($file);
            if (is_wp_error($editor)) {
                $result['errors'][] = 'Image editor unavailable';
            } else {
                $editor->resize($max_dimension, $max_dimension, false);
                $saved = $editor->save($file);
                if (is_wp_error($saved)) {
                    $result['errors'][] = 'Resize save failed';
                } else {
                    $result['resized_original'] = true;
                }
            }
        }

        if ($convert_to_avif) {
            $avif = $this->create_avif_from_attachment($attachment_id, $replace_original, $max_dimension);
            if (!empty($avif['created'])) {
                $result['avif_created'] = true;
                $result['avif_url'] = $avif['url'] ?? '';
            }
            if (!empty($avif['replaced'])) {
                $result['replaced_with_avif'] = true;
            }
            if (!empty($avif['error'])) {
                $result['errors'][] = $avif['error'];
            }
        }

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $fresh_file = get_attached_file($attachment_id);
        $new_metadata = wp_generate_attachment_metadata($attachment_id, $fresh_file);
        if (!is_wp_error($new_metadata) && is_array($new_metadata) && !empty($new_metadata)) {
            wp_update_attachment_metadata($attachment_id, $new_metadata);
            $result['metadata_updated'] = true;
        } else {
            $result['errors'][] = 'Metadata regeneration failed';
        }

        update_post_meta($attachment_id, '_seopc_media_optimized_at', current_time('mysql'));

        return $result;
    }

    private function create_avif_from_attachment($attachment_id, $replace_original = false, $max_dimension = 1920) {
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return ['error' => 'Source file missing'];
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $target_file = preg_replace('/\.[^.]+$/', '.avif', $file);
        $saved_path = '';

        if ($ext === 'svg') {
            if (!class_exists('Imagick')) {
                return ['error' => 'SVG to AVIF requires the Imagick PHP extension on this server'];
            }

            try {
                $imagick = new Imagick();
                $imagick->setBackgroundColor(new ImagickPixel('transparent'));
                $svg_blob = @file_get_contents($file);
                if ($svg_blob === false || trim($svg_blob) === '') {
                    return ['error' => 'SVG source could not be read'];
                }

                $imagick->readImageBlob($svg_blob);
                $imagick->setImageBackgroundColor(new ImagickPixel('transparent'));
                $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_MERGE);

                $width = $imagick->getImageWidth();
                $height = $imagick->getImageHeight();
                if ($width > 0 && $height > 0 && ($width > $max_dimension || $height > $max_dimension)) {
                    if ($width >= $height) {
                        $imagick->resizeImage($max_dimension, 0, Imagick::FILTER_LANCZOS, 1);
                    } else {
                        $imagick->resizeImage(0, $max_dimension, Imagick::FILTER_LANCZOS, 1);
                    }
                }

                $imagick->setImageFormat('AVIF');
                $imagick->setImageCompressionQuality(82);
                $imagick->writeImage($target_file);
                $saved_path = $target_file;
                $imagick->clear();
                $imagick->destroy();
            } catch (Exception $e) {
                return ['error' => 'SVG to AVIF conversion failed: ' . $e->getMessage()];
            }
        } else {
            $editor = wp_get_image_editor($file);
            if (is_wp_error($editor)) {
                return ['error' => 'AVIF image editor unavailable'];
            }

            if (method_exists($editor, 'set_quality')) {
                $editor->set_quality(82);
            }

            $saved = $editor->save($target_file, 'image/avif');
            if (is_wp_error($saved) || empty($saved['path']) || !file_exists($saved['path'])) {
                return ['error' => 'AVIF conversion failed on this server'];
            }
            $saved_path = $saved['path'];
        }

        if (!$saved_path || !file_exists($saved_path)) {
            return ['error' => 'AVIF output file was not created'];
        }

        $uploads = wp_get_upload_dir();
        $result = [
            'created' => true,
            'url' => str_replace($uploads['basedir'], $uploads['baseurl'], $saved_path),
            'path' => $saved_path,
            'replaced' => false,
        ];

        if ($replace_original) {
            update_attached_file($attachment_id, $saved_path);
            wp_update_post([
                'ID' => $attachment_id,
                'post_mime_type' => 'image/avif',
                'guid' => $result['url'],
            ]);

            $attached_metadata = wp_get_attachment_metadata($attachment_id);
            if (!is_array($attached_metadata)) {
                $attached_metadata = [];
            }
            if (!empty($uploads['basedir']) && strpos($saved_path, $uploads['basedir']) === 0) {
                $attached_metadata['file'] = ltrim(str_replace($uploads['basedir'], '', $saved_path), '/');
                wp_update_attachment_metadata($attachment_id, $attached_metadata);
            }

            $result['replaced'] = true;
        }

        return $result;
    }

    private function get_optimizer_candidates($limit = 3, $only_unoptimized = true) {
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => max(1, (int) $limit),
            'orderby' => 'ID',
            'order' => 'DESC',
            'fields' => 'ids',
        ];

        if ($only_unoptimized) {
            $args['meta_query'] = [[
                'key' => '_seopc_media_optimized_at',
                'compare' => 'NOT EXISTS',
            ]];
        }

        $query = new WP_Query($args);
        return !empty($query->posts) ? $query->posts : [];
    }

    private function run_media_optimization($limit = 3, $max_dimension = 1920, $only_unoptimized = true, $convert_to_avif = false, $replace_original = false) {
        $summary = [
            'processed' => 0,
            'resized_originals' => 0,
            'alt_updated' => 0,
            'metadata_updated' => 0,
            'avif_created' => 0,
            'replaced_with_avif' => 0,
            'errors' => 0,
            'items' => [],
        ];

        $candidates = $this->get_optimizer_candidates($limit, $only_unoptimized);
        foreach ($candidates as $attachment_id) {
            $item = $this->optimize_attachment((int) $attachment_id, $max_dimension, $convert_to_avif, $replace_original);
            $summary['items'][] = $item;
            $summary['processed']++;
            if (!empty($item['resized_original'])) $summary['resized_originals']++;
            if (!empty($item['alt_updated'])) $summary['alt_updated']++;
            if (!empty($item['metadata_updated'])) $summary['metadata_updated']++;
            if (!empty($item['avif_created'])) $summary['avif_created']++;
            if (!empty($item['replaced_with_avif'])) $summary['replaced_with_avif']++;
            if (!empty($item['errors'])) $summary['errors'] += count($item['errors']);
        }

        return $summary;
    }

    private function get_theme_directories() {
        $dirs = [];
        $child_dir = wp_normalize_path(get_stylesheet_directory());
        $parent_dir = wp_normalize_path(get_template_directory());
        if ($child_dir && is_dir($child_dir)) {
            $dirs['child'] = $child_dir;
        }
        if ($parent_dir && is_dir($parent_dir)) {
            $dirs['parent'] = $parent_dir;
        }
        return $dirs;
    }

    private function get_theme_files() {
        $theme_dirs = $this->get_theme_directories();
        $files = [];
        $seen = [];

        foreach ($theme_dirs as $theme_type => $theme_dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme_dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['php', 'html', 'htm'], true)) {
                    continue;
                }
                $path = wp_normalize_path($file->getPathname());
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                $files[] = [
                    'theme_type' => $theme_type,
                    'theme_dir' => $theme_dir,
                    'path' => $path,
                ];
            }
        }

        return $files;
    }

    private function scan_file_for_images($file_path, $theme_type = '', $theme_dir = '') {
        $results = [];
        $content = @file_get_contents($file_path);
        if ($content === false || trim($content) === '') {
            return $results;
        }

        if (!preg_match_all('/<img\b[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $results;
        }

        foreach ($matches[0] as $match) {
            $img_tag = $match[0];
            $offset = (int) $match[1];
            $before = substr($content, 0, $offset);
            $line = substr_count($before, "\n") + 1;
            $has_alt = preg_match('/\balt\s*=\s*("|\')[^"\']*\1/i', $img_tag);
            $has_width = preg_match('/\bwidth\s*=\s*("|\')?\d+("|\')?/i', $img_tag);
            $has_height = preg_match('/\bheight\s*=\s*("|\')?\d+("|\')?/i', $img_tag);
            $missing = [];
            if (!$has_alt) $missing[] = 'alt';
            if (!$has_width) $missing[] = 'width';
            if (!$has_height) $missing[] = 'height';
            if (!empty($missing)) {
                $relative = $file_path;
                if ($theme_dir && strpos($file_path, $theme_dir) === 0) {
                    $relative = ltrim(substr($file_path, strlen($theme_dir)), '/');
                }
                $src = '';
                $attachment_id = 0;
                if (preg_match('/\bsrc\s*=\s*("|\')([^"\']+)\1/i', $img_tag, $src_match)) {
                    $src = html_entity_decode($src_match[2], ENT_QUOTES, 'UTF-8');
                    $attachment_id = (int) attachment_url_to_postid($src);
                }

                $results[] = [
                    'theme_type' => $theme_type,
                    'file' => $file_path,
                    'relative' => $relative,
                    'line' => $line,
                    'missing' => $missing,
                    'tag' => $img_tag,
                    'src' => $src,
                    'attachment_id' => $attachment_id,
                ];
            }
        }

        return $results;
    }

    private function run_template_scan() {
        $summary = [
            'total_files' => 0,
            'total_img_tags' => 0,
            'issues_found' => 0,
            'child_files_scanned' => 0,
            'parent_files_scanned' => 0,
            'items' => [],
        ];

        $files = $this->get_theme_files();
        $summary['total_files'] = count($files);
        foreach ($files as $file_info) {
            $file_path = $file_info['path'];
            $theme_type = $file_info['theme_type'];
            $theme_dir = $file_info['theme_dir'];
            $content = @file_get_contents($file_path);
            if ($content === false) {
                continue;
            }
            if ($theme_type === 'child') $summary['child_files_scanned']++;
            if ($theme_type === 'parent') $summary['parent_files_scanned']++;
            if (preg_match_all('/<img\b[^>]*>/i', $content, $all_imgs)) {
                $summary['total_img_tags'] += count($all_imgs[0]);
            }
            $file_results = $this->scan_file_for_images($file_path, $theme_type, $theme_dir);
            if (!empty($file_results)) {
                $summary['issues_found'] += count($file_results);
                $summary['items'] = array_merge($summary['items'], $file_results);
            }
        }

        return $summary;
    }

    private function content_supported_post_types() {
        return apply_filters('seopc_media_content_post_types', ['post', 'page', 'product']);
    }

    private function scan_content_items($limit = 50) {
        $query = new WP_Query([
            'post_type' => $this->content_supported_post_types(),
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => max(1, min(500, (int) $limit)),
            'orderby' => 'ID',
            'order' => 'DESC',
            'fields' => 'ids',
        ]);

        $results = [
            'scanned_posts' => 0,
            'issues_found' => 0,
            'items' => [],
        ];

        foreach ($query->posts as $post_id) {
            $post = get_post($post_id);
            if (!$post || trim((string) $post->post_content) === '') {
                continue;
            }
            $results['scanned_posts']++;
            if (!preg_match_all('/<img\b[^>]*>/i', $post->post_content, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $post_items = [];
            foreach ($matches[0] as $match) {
                $img_tag = $match[0];
                $offset = (int) $match[1];
                $has_alt = preg_match('/\balt\s*=\s*("|\')[^"\']*\1/i', $img_tag);
                $has_width = preg_match('/\bwidth\s*=\s*("|\')?\d+("|\')?/i', $img_tag);
                $has_height = preg_match('/\bheight\s*=\s*("|\')?\d+("|\')?/i', $img_tag);
                $missing = [];
                if (!$has_alt) $missing[] = 'alt';
                if (!$has_width) $missing[] = 'width';
                if (!$has_height) $missing[] = 'height';
                if (empty($missing)) {
                    continue;
                }
                $fixed = $this->fix_single_img_tag($img_tag);
                if (!empty($fixed['changed'])) {
                    $post_items[] = [
                        'offset' => $offset,
                        'missing' => $missing,
                        'original' => $img_tag,
                        'updated' => $fixed['updated'],
                        'attachment_id' => $fixed['attachment_id'],
                        'src' => $fixed['src'],
                    ];
                }
            }

            if (!empty($post_items)) {
                $results['issues_found'] += count($post_items);
                $results['items'][] = [
                    'post_id' => $post_id,
                    'post_title' => get_the_title($post_id),
                    'post_type' => get_post_type($post_id),
                    'items' => $post_items,
                ];
            }
        }

        update_option('seopc_media_last_scan_results', $results, false);
        return $results;
    }

    private function apply_scan_results_to_db() {
        $scan = get_option('seopc_media_last_scan_results');
        $summary = ['posts_updated' => 0, 'tags_replaced' => 0, 'errors' => 0, 'items' => []];
        if (empty($scan['items']) || !is_array($scan['items'])) {
            return $summary;
        }

        foreach ($scan['items'] as $post_item) {
            $post_id = (int) $post_item['post_id'];
            $post = get_post($post_id);
            if (!$post) {
                $summary['errors']++;
                continue;
            }
            $content = (string) $post->post_content;
            $replace_count = 0;
            foreach ($post_item['items'] as $img_item) {
                $original = $img_item['original'];
                $updated = $img_item['updated'];
                if ($original !== $updated && strpos($content, $original) !== false) {
                    $content = preg_replace('/' . preg_quote($original, '/') . '/', str_replace('\\', '\\\\', $updated), $content, 1, $count);
                    $replace_count += (int) $count;
                }
            }
            if ($replace_count > 0 && $content !== $post->post_content) {
                $updated_post = wp_update_post(['ID' => $post_id, 'post_content' => $content], true);
                if (is_wp_error($updated_post)) {
                    $summary['errors']++;
                } else {
                    $summary['posts_updated']++;
                    $summary['tags_replaced'] += $replace_count;
                    $summary['items'][] = [
                        'post_id' => $post_id,
                        'post_title' => get_the_title($post_id),
                        'replaced' => $replace_count,
                    ];
                }
            }
        }

        return $summary;
    }

    private function get_media_item_summary($attachment_id) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return null;
        }
        $post = get_post($attachment_id);
        if (!$post || $post->post_type !== 'attachment') {
            return null;
        }

        $file = get_attached_file($attachment_id);
        $meta = wp_get_attachment_metadata($attachment_id);
        return [
            'attachment_id' => $attachment_id,
            'title' => get_the_title($attachment_id),
            'mime' => get_post_mime_type($attachment_id),
            'file' => $file,
            'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'url' => wp_get_attachment_url($attachment_id),
            'width' => isset($meta['width']) ? (int) $meta['width'] : 0,
            'height' => isset($meta['height']) ? (int) $meta['height'] : 0,
        ];
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'seo-performance-checker'));
        }

        $tab = isset($_GET['media_tab']) ? sanitize_key(wp_unslash($_GET['media_tab'])) : (isset($_GET['tab']) && $_GET['tab'] !== 'media-tools' ? sanitize_key(wp_unslash($_GET['tab'])) : 'optimizer');
        if (!in_array($tab, ['optimizer', 'checker', 'content-fixer'], true)) {
            $tab = 'optimizer';
        }

        $optimizer_results = null;
        $single_result = null;
        $checker_results = null;
        $content_scan = null;
        $content_apply = null;
        $theme_dirs = $this->get_theme_directories();
        $prefill_attachment_id = isset($_GET['attachment_id']) ? (int) $_GET['attachment_id'] : 0;

        if (isset($_POST['seopc_run_optimizer']) && check_admin_referer('seopc_run_optimizer_action', 'seopc_run_optimizer_nonce')) {
            $limit = max(1, min(10, (int) ($_POST['seopc_limit'] ?? 3)));
            $max_dimension = max(320, min(4096, (int) ($_POST['seopc_max_dimension'] ?? 1920)));
            $only_unoptimized = !empty($_POST['seopc_only_unoptimized']);
            $convert_to_avif = !empty($_POST['seopc_convert_to_avif']);
            $replace_original = !empty($_POST['seopc_replace_original']);
            $optimizer_results = $this->run_media_optimization($limit, $max_dimension, $only_unoptimized, $convert_to_avif, $replace_original);
            $tab = 'optimizer';
        }

        if (isset($_POST['seopc_optimize_single']) && check_admin_referer('seopc_optimize_single_action', 'seopc_optimize_single_nonce')) {
            $attachment_id = (int) ($_POST['seopc_attachment_id'] ?? 0);
            $max_dimension = max(320, min(4096, (int) ($_POST['seopc_single_max_dimension'] ?? 1920)));
            $convert_to_avif = !empty($_POST['seopc_single_convert_to_avif']);
            $replace_original = !empty($_POST['seopc_single_replace_original']);
            $single_result = $this->optimize_attachment($attachment_id, $max_dimension, $convert_to_avif, $replace_original);
            $prefill_attachment_id = $attachment_id;
            $tab = 'optimizer';
        }

        if (isset($_POST['seopc_run_checker']) && check_admin_referer('seopc_run_checker_action', 'seopc_run_checker_nonce')) {
            $checker_results = $this->run_template_scan();
            $tab = 'checker';
        }

        if (isset($_POST['seopc_scan_content_images']) && check_admin_referer('seopc_scan_content_images_action', 'seopc_scan_content_images_nonce')) {
            $limit = max(1, min(500, (int) ($_POST['seopc_content_limit'] ?? 50)));
            $content_scan = $this->scan_content_items($limit);
            $tab = 'content-fixer';
        }

        if (isset($_POST['seopc_apply_content_image_fixes']) && check_admin_referer('seopc_apply_content_image_fixes_action', 'seopc_apply_content_image_fixes_nonce')) {
            $content_apply = $this->apply_scan_results_to_db();
            $tab = 'content-fixer';
        }

        $last_scan = get_option('seopc_media_last_scan_results');
        $page_url = add_query_arg(['page' => 'seo-performance', 'tab' => 'media-tools'], admin_url('options-general.php'));
        $attachment_summary = $prefill_attachment_id ? $this->get_media_item_summary($prefill_attachment_id) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SEO Checker', 'seo-performance-checker'); ?></h1>
            <?php if (class_exists('SEOPC_Admin_Menu')) { SEOPC_Admin_Menu::render_tabs('media-tools'); } ?>
            <h2><?php esc_html_e('Media Tools', 'seo-performance-checker'); ?></h2>
            <p><?php esc_html_e('Fix image alt text and dimensions, scan theme templates and content HTML, and resize or convert attachments to AVIF.', 'seo-performance-checker'); ?></p>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg('media_tab', 'optimizer', $page_url)); ?>" class="nav-tab <?php echo $tab === 'optimizer' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Safe Media Optimizer', 'seo-performance-checker'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('media_tab', 'checker', $page_url)); ?>" class="nav-tab <?php echo $tab === 'checker' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Template Image Checker', 'seo-performance-checker'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('media_tab', 'content-fixer', $page_url)); ?>" class="nav-tab <?php echo $tab === 'content-fixer' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Content Image DB Fixer', 'seo-performance-checker'); ?></a>
            </h2>

            <?php if ($tab === 'optimizer') : ?>
                <p><?php esc_html_e('Use small batches, especially if another optimizer plugin is active. Start with 1 to 3 images per run.', 'seo-performance-checker'); ?></p>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:20px;align-items:start;">
                    <div class="seopc-section">
                        <h2><?php esc_html_e('Single media item by attachment ID', 'seo-performance-checker'); ?></h2>
                        <form method="post">
                            <?php wp_nonce_field('seopc_optimize_single_action', 'seopc_optimize_single_nonce'); ?>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="seopc_attachment_id"><?php esc_html_e('Attachment ID', 'seo-performance-checker'); ?></label></th>
                                    <td><input name="seopc_attachment_id" id="seopc_attachment_id" type="number" value="<?php echo esc_attr($prefill_attachment_id ?: ''); ?>" min="1" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="seopc_single_max_dimension"><?php esc_html_e('Max width / height', 'seo-performance-checker'); ?></label></th>
                                    <td><input name="seopc_single_max_dimension" id="seopc_single_max_dimension" type="number" value="1920" min="320" max="4096" class="small-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Actions', 'seo-performance-checker'); ?></th>
                                    <td>
                                        <label><input type="checkbox" name="seopc_single_convert_to_avif" value="1" /> <?php esc_html_e('Create AVIF version', 'seo-performance-checker'); ?></label><br>
                                        <label><input type="checkbox" name="seopc_single_replace_original" value="1" /> <?php esc_html_e('Replace original attachment file with AVIF if conversion succeeds', 'seo-performance-checker'); ?></label>
                                    </td>
                                </tr>
                            </table>
                            <p><button type="submit" name="seopc_optimize_single" class="button button-primary"><?php esc_html_e('Optimize this attachment', 'seo-performance-checker'); ?></button></p>
                        </form>

                        <?php if ($attachment_summary) : ?>
                            <hr>
                            <h3><?php esc_html_e('Selected attachment', 'seo-performance-checker'); ?></h3>
                            <table class="widefat striped"><tbody>
                                <tr><td><strong>ID</strong></td><td><?php echo (int) $attachment_summary['attachment_id']; ?></td></tr>
                                <tr><td><strong><?php esc_html_e('Title', 'seo-performance-checker'); ?></strong></td><td><?php echo esc_html($attachment_summary['title']); ?></td></tr>
                                <tr><td><strong><?php esc_html_e('MIME', 'seo-performance-checker'); ?></strong></td><td><?php echo esc_html($attachment_summary['mime']); ?></td></tr>
                                <tr><td><strong><?php esc_html_e('Dimensions', 'seo-performance-checker'); ?></strong></td><td><?php echo esc_html(($attachment_summary['width'] ?: 0) . ' × ' . ($attachment_summary['height'] ?: 0)); ?></td></tr>
                                <tr><td><strong><?php esc_html_e('Alt text', 'seo-performance-checker'); ?></strong></td><td><?php echo esc_html($attachment_summary['alt'] ?: '—'); ?></td></tr>
                                <tr><td><strong><?php esc_html_e('File', 'seo-performance-checker'); ?></strong></td><td style="word-break:break-all;"><?php echo esc_html($attachment_summary['file']); ?></td></tr>
                                <tr><td><strong><?php esc_html_e('URL', 'seo-performance-checker'); ?></strong></td><td><a href="<?php echo esc_url($attachment_summary['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($attachment_summary['url']); ?></a></td></tr>
                            </tbody></table>
                        <?php endif; ?>

                        <?php if ($single_result !== null) : ?>
                            <hr>
                            <h3><?php esc_html_e('Single item result', 'seo-performance-checker'); ?></h3>
                            <table class="widefat striped"><tbody>
                                <tr><td><strong>ID</strong></td><td><?php echo (int) $single_result['attachment_id']; ?></td></tr>
                                <tr><td><strong><?php esc_html_e('Resized original', 'seo-performance-checker'); ?></strong></td><td><?php echo !empty($single_result['resized_original']) ? 'Yes' : 'No'; ?></td></tr>
                                <tr><td><strong><?php esc_html_e('Alt text updated', 'seo-performance-checker'); ?></strong></td><td><?php echo !empty($single_result['alt_updated']) ? 'Yes' : 'No'; ?></td></tr>
                                <tr><td><strong><?php esc_html_e('Metadata updated', 'seo-performance-checker'); ?></strong></td><td><?php echo !empty($single_result['metadata_updated']) ? 'Yes' : 'No'; ?></td></tr>
                                <tr><td><strong><?php esc_html_e('AVIF created', 'seo-performance-checker'); ?></strong></td><td><?php echo !empty($single_result['avif_created']) ? 'Yes' : 'No'; ?></td></tr>
                                <tr><td><strong><?php esc_html_e('Replaced with AVIF', 'seo-performance-checker'); ?></strong></td><td><?php echo !empty($single_result['replaced_with_avif']) ? 'Yes' : 'No'; ?></td></tr>
                                <tr><td><strong><?php esc_html_e('Errors', 'seo-performance-checker'); ?></strong></td><td><?php echo !empty($single_result['errors']) ? esc_html(implode('; ', $single_result['errors'])) : '—'; ?></td></tr>
                            </tbody></table>
                        <?php endif; ?>
                    </div>

                    <div class="seopc-section">
                        <h2><?php esc_html_e('Safe optimization batch', 'seo-performance-checker'); ?></h2>
                        <form method="post">
                            <?php wp_nonce_field('seopc_run_optimizer_action', 'seopc_run_optimizer_nonce'); ?>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="seopc_limit"><?php esc_html_e('Batch size', 'seo-performance-checker'); ?></label></th>
                                    <td><input name="seopc_limit" id="seopc_limit" type="number" value="3" min="1" max="10" class="small-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="seopc_max_dimension"><?php esc_html_e('Max width / height', 'seo-performance-checker'); ?></label></th>
                                    <td><input name="seopc_max_dimension" id="seopc_max_dimension" type="number" value="1920" min="320" max="4096" class="small-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Processing mode', 'seo-performance-checker'); ?></th>
                                    <td>
                                        <label><input type="checkbox" name="seopc_only_unoptimized" value="1" checked="checked" /> <?php esc_html_e('Process only images not yet optimized by this tool', 'seo-performance-checker'); ?></label><br>
                                        <label><input type="checkbox" name="seopc_convert_to_avif" value="1" /> <?php esc_html_e('Create AVIF versions during batch run', 'seo-performance-checker'); ?></label><br>
                                        <label><input type="checkbox" name="seopc_replace_original" value="1" /> <?php esc_html_e('Replace original attachment file with AVIF when possible', 'seo-performance-checker'); ?></label>
                                    </td>
                                </tr>
                            </table>
                            <p><button type="submit" name="seopc_run_optimizer" class="button button-primary"><?php esc_html_e('Run safe optimization batch', 'seo-performance-checker'); ?></button></p>
                        </form>
                    </div>
                </div>

                <?php if ($optimizer_results !== null) : ?>
                    <hr />
                    <h2><?php esc_html_e('Batch results', 'seo-performance-checker'); ?></h2>
                    <table class="widefat striped" style="max-width: 950px;"><tbody>
                        <tr><td><strong><?php esc_html_e('Processed', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $optimizer_results['processed']; ?></td></tr>
                        <tr><td><strong><?php esc_html_e('Resized originals', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $optimizer_results['resized_originals']; ?></td></tr>
                        <tr><td><strong><?php esc_html_e('Alt text updated', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $optimizer_results['alt_updated']; ?></td></tr>
                        <tr><td><strong><?php esc_html_e('Metadata updated', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $optimizer_results['metadata_updated']; ?></td></tr>
                        <tr><td><strong><?php esc_html_e('AVIF created', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $optimizer_results['avif_created']; ?></td></tr>
                        <tr><td><strong><?php esc_html_e('Replaced with AVIF', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $optimizer_results['replaced_with_avif']; ?></td></tr>
                        <tr><td><strong><?php esc_html_e('Error count', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $optimizer_results['errors']; ?></td></tr>
                    </tbody></table>

                    <h3 style="margin-top:24px;"><?php esc_html_e('Processed items', 'seo-performance-checker'); ?></h3>
                    <div style="max-height:500px; overflow:auto; border:1px solid #ccd0d4; background:#fff;">
                        <table class="widefat striped">
                            <thead><tr><th>ID</th><th><?php esc_html_e('File', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Resized', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Alt', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Metadata', 'seo-performance-checker'); ?></th><th><?php esc_html_e('AVIF', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Errors', 'seo-performance-checker'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($optimizer_results['items'] as $item) : ?>
                                <tr>
                                    <td><?php echo (int) $item['attachment_id']; ?></td>
                                    <td style="word-break: break-all;"><?php echo esc_html(basename((string) $item['file'])); ?> <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'seo-performance', 'tab' => 'media-tools', 'media_tab' => 'optimizer', 'attachment_id' => (int) $item['attachment_id']], admin_url('options-general.php'))); ?>"><?php esc_html_e('Open by ID', 'seo-performance-checker'); ?></a></td>
                                    <td><?php echo !empty($item['resized_original']) ? 'Yes' : 'No'; ?></td>
                                    <td><?php echo !empty($item['alt_updated']) ? 'Yes' : 'No'; ?></td>
                                    <td><?php echo !empty($item['metadata_updated']) ? 'Yes' : 'No'; ?></td>
                                    <td><?php echo !empty($item['avif_created']) ? 'Yes' : 'No'; ?></td>
                                    <td style="word-break: break-word;"><?php echo !empty($item['errors']) ? esc_html(implode('; ', $item['errors'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'checker') : ?>
                <p><?php esc_html_e('This checks raw <img> tags in child and parent theme template files for missing alt, width, and height attributes.', 'seo-performance-checker'); ?></p>
                <ul style="list-style:disc; margin-left:20px;">
                    <?php if (!empty($theme_dirs['child'])) : ?><li><strong><?php esc_html_e('Child theme:', 'seo-performance-checker'); ?></strong> <code><?php echo esc_html($theme_dirs['child']); ?></code></li><?php endif; ?>
                    <?php if (!empty($theme_dirs['parent'])) : ?><li><strong><?php esc_html_e('Parent theme:', 'seo-performance-checker'); ?></strong> <code><?php echo esc_html($theme_dirs['parent']); ?></code></li><?php endif; ?>
                </ul>
                <p><?php esc_html_e('For real rendered heading and image checks, use the main SEO analysis on a published page. That analysis now reads the front-end HTML instead of only the editor content.', 'seo-performance-checker'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('seopc_run_checker_action', 'seopc_run_checker_nonce'); ?>
                    <p><button type="submit" name="seopc_run_checker" class="button button-primary"><?php esc_html_e('Scan child + parent theme templates', 'seo-performance-checker'); ?></button></p>
                </form>

                <?php if ($checker_results !== null) : ?>
                    <hr />
                    <h2><?php esc_html_e('Checker results', 'seo-performance-checker'); ?></h2>
                    <table class="widefat striped" style="max-width:950px;"><tbody>
                        <tr><td><strong><?php esc_html_e('Total files scanned', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $checker_results['total_files']; ?></td></tr>
                        <tr><td><strong><?php esc_html_e('Child theme files scanned', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $checker_results['child_files_scanned']; ?></td></tr>
                        <tr><td><strong><?php esc_html_e('Parent theme files scanned', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $checker_results['parent_files_scanned']; ?></td></tr>
                        <tr><td><strong><?php esc_html_e('Total img tags found', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $checker_results['total_img_tags']; ?></td></tr>
                        <tr><td><strong><?php esc_html_e('Issues found', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $checker_results['issues_found']; ?></td></tr>
                    </tbody></table>

                    <?php if (!empty($checker_results['items'])) : ?>
                        <h3 style="margin-top:24px;"><?php esc_html_e('Template image issues', 'seo-performance-checker'); ?></h3>
                        <div style="max-height:650px; overflow:auto; border:1px solid #ccd0d4; background:#fff;">
                            <table class="widefat striped">
                                <thead><tr><th><?php esc_html_e('Theme', 'seo-performance-checker'); ?></th><th><?php esc_html_e('File', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Line', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Missing', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Tag', 'seo-performance-checker'); ?></th></tr></thead>
                                <tbody>
                                <?php foreach ($checker_results['items'] as $item) : ?>
                                    <tr>
                                        <td><?php echo esc_html(ucfirst($item['theme_type'])); ?></td>
                                        <td style="word-break: break-all;"><?php echo esc_html($item['relative']); ?></td>
                                        <td><?php echo (int) $item['line']; ?></td>
                                        <td><?php echo esc_html(implode(', ', $item['missing'])); ?></td>
                                        <td style="word-break: break-word;"><code><?php echo esc_html($item['tag']); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else : ?>
                        <p><strong><?php esc_html_e('No missing alt, width, or height found in child or parent theme template img tags.', 'seo-performance-checker'); ?></strong></p>
                    <?php endif; ?>
                <?php endif; ?>

            <?php else : ?>
                <p><?php esc_html_e('Scan post, page, and product content for raw image HTML, then write missing alt, width, and height back into the database.', 'seo-performance-checker'); ?></p>
                <form method="post" style="margin-bottom:16px;">
                    <?php wp_nonce_field('seopc_scan_content_images_action', 'seopc_scan_content_images_nonce'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="seopc_content_limit"><?php esc_html_e('How many content items to scan', 'seo-performance-checker'); ?></label></th>
                            <td>
                                <input name="seopc_content_limit" id="seopc_content_limit" type="number" value="50" min="1" max="500" class="small-text" />
                                <p class="description"><?php echo esc_html__('Scans newest items first across: ', 'seo-performance-checker') . esc_html(implode(', ', $this->content_supported_post_types())); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" name="seopc_scan_content_images" class="button button-primary"><?php esc_html_e('Scan content', 'seo-performance-checker'); ?></button></p>
                </form>

                <?php if ($content_scan !== null) : ?>
                    <table class="widefat striped" style="max-width:950px; margin-bottom:16px;"><tbody>
                        <tr><td><strong><?php esc_html_e('Scanned posts', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $content_scan['scanned_posts']; ?></td></tr>
                        <tr><td><strong><?php esc_html_e('Fixable image tags found', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $content_scan['issues_found']; ?></td></tr>
                    </tbody></table>
                <?php endif; ?>

                <?php if (!empty($last_scan['items'])) : ?>
                    <form method="post" style="margin-bottom:20px;">
                        <?php wp_nonce_field('seopc_apply_content_image_fixes_action', 'seopc_apply_content_image_fixes_nonce'); ?>
                        <p><button type="submit" name="seopc_apply_content_image_fixes" class="button button-secondary"><?php esc_html_e('Apply fixes to DB', 'seo-performance-checker'); ?></button></p>
                    </form>

                    <?php if ($content_apply !== null) : ?>
                        <table class="widefat striped" style="max-width:950px; margin-bottom:20px;"><tbody>
                            <tr><td><strong><?php esc_html_e('Posts updated', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $content_apply['posts_updated']; ?></td></tr>
                            <tr><td><strong><?php esc_html_e('Image tags replaced', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $content_apply['tags_replaced']; ?></td></tr>
                            <tr><td><strong><?php esc_html_e('Errors', 'seo-performance-checker'); ?></strong></td><td><?php echo (int) $content_apply['errors']; ?></td></tr>
                        </tbody></table>
                    <?php endif; ?>

                    <h2><?php esc_html_e('Last scan results', 'seo-performance-checker'); ?></h2>
                    <div style="max-height:650px; overflow:auto; border:1px solid #ccd0d4; background:#fff;">
                        <table class="widefat striped">
                            <thead><tr><th><?php esc_html_e('Post', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Type', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Missing', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Original', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Updated', 'seo-performance-checker'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($last_scan['items'] as $post_item) : ?>
                                <?php foreach ($post_item['items'] as $img_item) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($post_item['post_title']); ?></strong><br>ID: <?php echo (int) $post_item['post_id']; ?><?php if (!empty($img_item['attachment_id'])) : ?> <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'seo-performance', 'tab' => 'media-tools', 'media_tab' => 'optimizer', 'attachment_id' => (int) $img_item['attachment_id']], admin_url('options-general.php'))); ?>"><?php esc_html_e('Open media ID', 'seo-performance-checker'); ?></a><?php endif; ?></td>
                                        <td><?php echo esc_html($post_item['post_type']); ?></td>
                                        <td><?php echo esc_html(implode(', ', $img_item['missing'])); ?></td>
                                        <td style="word-break: break-word;"><code><?php echo esc_html($img_item['original']); ?></code></td>
                                        <td style="word-break: break-word;"><code><?php echo esc_html($img_item['updated']); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <p><?php esc_html_e('No saved scan results yet. Run a scan first.', 'seo-performance-checker'); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
