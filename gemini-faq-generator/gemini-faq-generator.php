<?php
/**
 * Plugin Name: Gemini FAQ Generator
 * Plugin URI:  https://mai.kosodante.com/gemini-faq-generator
 * Description: Automatically generates FAQs for your WordPress posts/pages using Google Gemini API.
 * Version:     1.0.0
 * Author:      Mai Nakagawa
 * Author URI:  https://mai.kosodante.com
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// プラグインのアクティベーション時に実行される関数
function gemini_faq_generator_activate() {
    // 将来的に必要な初期設定があればここに記述
}
register_activation_hook( __FILE__, 'gemini_faq_generator_activate' );

// プラグインのディアクティベーション時に実行される関数
function gemini_faq_generator_deactivate() {
    // 将来的に必要なクリーンアップがあればここに記述
}
register_deactivation_hook( __FILE__, 'gemini_faq_generator_deactivate' );

// ショートコードの登録
function gemini_faq_shortcode( $atts ) {
    // ここにFAQ生成と表示のロジックを記述
    global $post; // 現在の投稿オブジェクトを取得
    $post_id = isset( $post->ID ) ? $post->ID : 0; // 投稿IDを取得、なければ0

    // <h3>FAQ</h3> の見出しを追加
    $output = '<h3>FAQ</h3>';
    $output .= '<div id="gemini-faq-container" data-post-id="' . esc_attr( $post_id ) . '">FAQを読み込み中...</div>';

    // 構造化データ (JSON-LD) を出力
    $json_ld = get_post_meta( $post_id, '_gemini_faq_json_ld', true );
    if ( ! empty( $json_ld ) ) {
        $output .= '<script type="application/ld+json">' . $json_ld . '</script>';
    }

    return $output;
}
add_shortcode( 'gemini_faq', 'gemini_faq_shortcode' );

// JavaScriptファイルの読み込み
function gemini_faq_enqueue_scripts() {
    wp_enqueue_style(
        'gemini-faq-style',
        plugins_url( 'css/gemini-faq.css', __FILE__ ),
        array(),
        '1.0.0'
    );

    wp_enqueue_script(
        'gemini-faq-script',
        plugins_url( 'js/gemini-faq.js', __FILE__ ),
        array( 'jquery' ),
        '1.0.0',
        true // フッターで読み込む
    );

    // AJAX URLをJavaScriptに渡す
    wp_localize_script(
        'gemini-faq-script',
        'geminiFaqAjax',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'gemini_faq_nonce' ),
        )
    );
}
add_action( 'wp_enqueue_scripts', 'gemini_faq_enqueue_scripts' );

// AJAXリクエストのハンドリング
/**
 * 指定されたURLのコンテンツからFAQを生成し、キャッシュするヘルパー関数
 * @param string $current_page_url FAQを生成する元のURL
 * @param int $post_id 関連する投稿ID
 * @return array|false 生成されたFAQの配列、または失敗した場合はfalse
 */
function _gemini_faq_generate_and_cache_for_url($current_page_url, $post_id) {
    $api_key = get_option( 'gemini_faq_api_key' );
    if ( empty( $api_key ) ) {
        error_log( 'Gemini FAQ: API Key is not set.' );
        return false;
    }

    // キャッシュキーの生成 (URLと投稿IDを組み合わせる)
    $cache_key = 'gemini_faq_' . md5( $current_page_url . $post_id );
    $cache_duration_days = get_option( 'gemini_faq_cache_duration', 1 ); // 設定値を取得、なければ1日

    // キャッシュがある場合はそれを返す
    $cached_data = get_transient( $cache_key );
    if ( false !== $cached_data && is_array($cached_data) && isset($cached_data['faqs']) && is_array($cached_data['faqs']) && isset($cached_data['json_ld']) ) {
        error_log('Gemini FAQ: Cache hit for ' . $current_page_url);
        return $cached_data;
    }

    error_log('Gemini FAQ: Cache miss for ' . $current_page_url . '. Generating new FAQ.');

    // キャッシュがない場合、コンテンツを取得してGemini APIを呼び出す
    $html_content = wp_remote_retrieve_body( wp_remote_get( $current_page_url, array( 'timeout' => 10 ) ) );

    if ( is_wp_error( $html_content ) || empty( $html_content ) ) {
        error_log( 'Gemini FAQ: Failed to fetch page content for ' . $current_page_url );
        return false;
    }

    // HTMLからテキストを抽出 (簡易版)
    $text_content = strip_tags( $html_content );
    $text_content = preg_replace( '/\s+/', ' ', $text_content ); // 複数の空白を単一の空白に
    $text_content = mb_substr( $text_content, 0, 5000 ); // Gemini APIのトークン制限を考慮し、最大5000文字に制限

    if ( empty( $text_content ) ) {
        error_log( 'Gemini FAQ: Could not extract text from the page ' . $current_page_url );
        return false;
    }

    // Gemini API呼び出し
    $gemini_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $api_key;
    $headers = array(
        'Content-Type' => 'application/json',
    );
    $body = json_encode(array(
        'contents' => array(
            array(
                'parts' => array(
                    array('text' => "以下のテキストに基づいて、想定される質問と回答のペアを5つ作成してください.\n各ペアは、必ず\"Q: \"で始まる質問と\"A: \"で始まる回答の形式にしてください.\n\n---\n" . $text_content . "\n---"),
                ),
            ),
        ),
    ));

    $response = wp_remote_post( $gemini_url, array(
        'headers' => $headers,
        'body'    => $body,
        'timeout' => 30, // タイムアウトを長めに設定
    ));

    if ( is_wp_error( $response ) ) {
        error_log( 'Gemini FAQ: API request failed: ' . $response->get_error_message() );
        return false;
    }

    $response_body = wp_remote_retrieve_body( $response );
    $gemini_data = json_decode( $response_body, true );

    if ( ! isset( $gemini_data['candidates'][0]['content']['parts'][0]['text'] ) ) {
        error_log( 'Gemini FAQ: Failed to parse Gemini API response for ' . $current_page_url );
        return false;
    }

    $gemini_text = $gemini_data['candidates'][0]['content']['parts'][0]['text'];

    // GeminiのテキストをFAQ配列にパース
    $faqs = array();
    $lines = explode( "\n", $gemini_text );
    $current_question = '';
    $current_answer = '';

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( empty( $line ) ) continue;

        if ( strpos( $line, 'Q:' ) === 0 ) {
            if ( ! empty( $current_question ) ) {
                $faqs[] = array( 'question' => trim( $current_question ), 'answer' => trim( $current_answer ) );
            }
            $current_question = substr( $line, 2 );
            $current_answer = '';
        } elseif ( strpos( $line, 'A:' ) === 0 ) {
            $current_answer .= substr( $line, 2 );
        } else {
            // 回答の続き
            $current_answer .= ' ' . $line;
        }
    }
    if ( ! empty( $current_question ) ) {
        $faqs[] = array( 'question' => trim( $current_question ), 'answer' => trim( $current_answer ) );
    }

    if ( empty( $faqs ) ) {
        error_log( 'Gemini FAQ: No FAQ content could be generated from Gemini for ' . $current_page_url );
        return false;
    }

    // JSON-LD (FAQPage) 構造化データを生成
    $json_ld_data = array(
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array(),
    );

    foreach ( $faqs as $faq ) {
        $json_ld_data['mainEntity'][] = array(
            '@type' => 'Question',
            'name' => $faq['question'],
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text' => $faq['answer'],
            ),
        );
    }
    $json_ld_output = json_encode( $json_ld_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

    // キャッシュに保存
    set_transient( $cache_key, array('faqs' => $faqs, 'json_ld' => $json_ld_output), $cache_duration_days * DAY_IN_SECONDS );

    return array('faqs' => $faqs, 'json_ld' => $json_ld_output);
}

function gemini_faq_ajax_handler() {
    check_ajax_referer( 'gemini_faq_nonce', 'nonce' );

    $post_id = intval( $_POST['post_id'] );
    $current_page_url = sanitize_url( $_POST['current_page_url'] );

    error_log('Gemini FAQ AJAX: Received post_id = ' . $post_id . ', current_page_url = ' . $current_page_url);

    // post_idが0の場合、URLから投稿IDを推測する
    if ( $post_id === 0 && ! empty( $current_page_url ) ) {
        $inferred_post_id = url_to_postid( $current_page_url );
        if ( $inferred_post_id > 0 ) {
            $post_id = $inferred_post_id;
            error_log('Gemini FAQ AJAX: Inferred post_id from URL: ' . $post_id);
        }
    }

    if ( $post_id === 0 ) { // 推測後も0の場合
        wp_send_json_error( 'Invalid request data: Post ID missing or zero, and could not be inferred from URL.' );
    }
    if ( ! $current_page_url ) {
        wp_send_json_error( 'Invalid request data: Current page URL missing.' );
    }

    // キャッシュから取得、または生成
    $result = _gemini_faq_generate_and_cache_for_url($current_page_url, $post_id);

    if ( false === $result || !isset($result['faqs']) || !is_array($result['faqs']) ) {
        wp_send_json_error( 'Failed to generate or retrieve FAQ data.' );
    }

    wp_send_json_success( $result['faqs'] );
}
add_action( 'wp_ajax_gemini_faq', 'gemini_faq_ajax_handler' );
add_action( 'wp_ajax_nopriv_gemini_faq', 'gemini_faq_ajax_handler' );

/**
 * 投稿が保存されたときにFAQを生成・キャッシュする
 * @param int $post_id 投稿ID
 * @param WP_Post $post 投稿オブジェクト
 * @param bool $update 投稿が更新された場合はtrue、新規作成の場合はfalse
 */
function gemini_faq_generate_on_post_save( $post_id, $post, $update ) {
    // 自動保存、リビジョン、またはクイック編集の場合は処理しない
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_POST['_inline_edit'] ) ) return; // クイック編集

    // 投稿タイプが公開されているものか確認 (例: post, page)
    $post_type = get_post_type( $post_id );
    $public_post_types = apply_filters( 'gemini_faq_public_post_types', array( 'post', 'page' ) );
    if ( ! in_array( $post_type, $public_post_types ) ) return;

    // 投稿コンテンツにショートコードが含まれているか確認
    if ( has_shortcode( $post->post_content, 'gemini_faq' ) ) {
        $current_page_url = get_permalink( $post_id );
        if ( ! $current_page_url ) {
            error_log( 'Gemini FAQ: Could not get permalink for post ID ' . $post_id );
            return;
        }

        error_log( 'Gemini FAQ: Triggering FAQ generation on post save for ' . $current_page_url );
        // FAQを生成し、キャッシュに保存
        $result = _gemini_faq_generate_and_cache_for_url( $current_page_url, $post_id );

        if ( $result && isset( $result['json_ld'] ) ) {
            update_post_meta( $post_id, '_gemini_faq_json_ld', $result['json_ld'] );
        } else {
            delete_post_meta( $post_id, '_gemini_faq_json_ld' ); // 生成失敗時は削除
        }
    }
}
add_action( 'save_post', 'gemini_faq_generate_on_post_save', 10, 3 );

// プラグイン設定ページ（APIキー入力用）
function gemini_faq_settings_page() {
    add_options_page(
        'Gemini FAQ Settings',
        'Gemini FAQ',
        'manage_options',
        'gemini-faq-settings',
        'gemini_faq_settings_page_content'
    );
}
add_action( 'admin_menu', 'gemini_faq_settings_page' );

function gemini_faq_settings_page_content() {
    // 設定ページのHTMLを記述
    ?>
    <div class="wrap">
        <h1>Gemini FAQ Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'gemini_faq_options_group' );
            do_settings_sections( 'gemini-faq-settings' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function gemini_faq_register_settings() {
    register_setting(
        'gemini_faq_options_group',
        'gemini_faq_api_key',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        )
    );

    add_settings_section(
        'gemini_faq_api_section',
        'Gemini API Settings',
        null,
        'gemini-faq-settings'
    );

    add_settings_field(
        'gemini_faq_api_key_field',
        'Gemini API Key',
        'gemini_faq_api_key_callback',
        'gemini-faq-settings',
        'gemini_faq_api_section'
    );

    register_setting(
        'gemini_faq_options_group',
        'gemini_faq_cache_duration',
        array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1, // デフォルトは1日
        )
    );

    add_settings_field(
        'gemini_faq_cache_duration_field',
        'FAQキャッシュ期間 (日数)',
        'gemini_faq_cache_duration_callback',
        'gemini-faq-settings',
        'gemini_faq_api_section'
    );
}
add_action( 'admin_init', 'gemini_faq_register_settings' );

function gemini_faq_api_key_callback() {
    $api_key = get_option( 'gemini_faq_api_key' );
    echo '<input type="text" name="gemini_faq_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text" />';
}

function gemini_faq_cache_duration_callback() {
    $cache_duration = get_option( 'gemini_faq_cache_duration', 1 ); // デフォルトは1日
    echo '<input type="number" name="gemini_faq_cache_duration" value="' . esc_attr( $cache_duration ) . '" class="small-text" min="1" /> 日';
}
