<?php

class Front18_Frontend {

    private $should_run_cached = null;

    public function init() {
        add_action( 'wp_head', array( $this, 'inject_anti_flicker' ), 0 );
        add_action( 'wp_head', array( $this, 'inject_sdk_loader' ), 1 );
        add_shortcode( 'front18', array( $this, 'render_shortcode' ) );
        add_shortcode( 'front18_lock', array( $this, 'render_lock_shortcode' ) );
        add_filter( 'language_attributes', array( $this, 'inject_html_class' ), 99 );
    }

    public function inject_html_class( $attributes ) {
        if ( ! $this->should_run() ) return $attributes;
        
        $synced_config = get_option( 'front18_synced_config', array() );
        $display_mode  = !empty($synced_config['display_mode']) ? $synced_config['display_mode'] : 'global_lock';
        
        $payload = $attributes . ' class="front18-hide"';
        
        // Blindagem Nuclear Zero-Ms contra Plugins de Cache (WP Rocket/LiteSpeed CSS Defer)
        // Se a página for MODO GLOBAL, o bloqueio injetado no HTML Root destrói a chance do Cache carregar a imagem primeiro.
        if ( $display_mode !== 'granular' && $display_mode !== 'blur_media' ) {
            $payload .= ' style="opacity: 0.01 !important; pointer-events: none !important;"';
        }
        
        return $payload;
    }

    public function inject_anti_flicker() {
        if ( ! $this->should_run() ) return;

        $synced_config = get_option( 'front18_synced_config', array() );
        $display_mode  = !empty($synced_config['display_mode']) ? $synced_config['display_mode'] : 'global_lock';
        $color_bg      = !empty($synced_config['color_bg']) ? $synced_config['color_bg'] : '#0f172a';
        
        $blur_amount   = isset($synced_config['blur_amount']) ? (int)$synced_config['blur_amount'] : 25;
        $blur_selector = !empty($synced_config['blur_selector']) ? $synced_config['blur_selector'] : 'img, video, iframe, [data-front18="locked"]';
        $protection_level = isset($synced_config['level']) ? (int)$synced_config['level'] : 1;
        
        $protected_ids = get_option( 'front18_protected_media_ids', array() );

        // Fallback avançado sempre incluso para casos fora da biblioteca de mídia (Ex: Elementor Background)
        $locked_tag_selector = 'html.front18-hide [data-front18="locked"], html.front18-hide .front18-locked';

        $formatted_selectors = '';

        // Arquitetura Híbrida: Sempre aplica o seletor genérico, mas soma a Granularidade se houver.
        $formatted_selectors = implode(', ', array_map(function($sel) {
            return 'html.front18-hide ' . trim($sel);
        }, explode(',', $blur_selector)));

        if (empty($formatted_selectors)) {
            $formatted_selectors = 'html.front18-hide img, html.front18-hide video, html.front18-hide iframe, html.front18-hide .e-con, html.front18-hide .elementor-section, html.front18-hide .wp-block-cover, ' . $locked_tag_selector;
        } else {
            $formatted_selectors .= ', ' . $locked_tag_selector . ', html.front18-hide .e-con, html.front18-hide .elementor-section, html.front18-hide .wp-block-cover';
        }

        if ( !empty($protected_ids) && is_array($protected_ids) ) {
            // Soma a Arquitetura Granular à lista de defesas
            $granular_selectors = implode(', ', array_map(function($id) {
                return 'html.front18-hide .wp-image-' . (int)$id . ', html.front18-hide .attachment-' . (int)$id;
            }, $protected_ids));
            
            $formatted_selectors .= ', ' . $granular_selectors;
        }
        ?>
        <!-- FRONT18: ANTI-FLICKER EXTREMO (BLINDADO CONTRA MINIFICAÇÃO) -->
        <style id="front18-antiflicker-css" data-rocket-exclude="true" data-no-optimize="1" data-no-minify="1" data-cfasync="false">
            /* Camada de Controle Flexível: Adaptável com base na configuração da Edge SaaS */
            <?php if ( $display_mode === 'granular' || $display_mode === 'blur_media' ): ?>
            <?php if ( $protection_level === 2 || $protection_level === 3 ): ?>
            html.front18-hide { 
                background-color: <?php echo esc_attr($color_bg); ?> !important;
                transition: background-color 0.3s ease; 
            }
            <?php endif; ?>
            html.front18-hide body { 
                pointer-events: none !important; 
                overflow-x: hidden !important; 
            }
            <?php echo $formatted_selectors; ?> { 
                <?php if ( $protection_level === 3 ): ?>
                opacity: 0 !important; display: none !important;
                <?php elseif ( $protection_level === 2 ): ?>
                opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; user-select: none !important;
                <?php else: ?>
                filter: blur(<?php echo $blur_amount; ?>px) grayscale(100%) !important;
                user-select: none !important;
                -webkit-user-drag: none !important;
                <?php endif; ?>
            }
            <?php else: ?>
            html.front18-hide { 
                background-color: <?php echo esc_attr($color_bg); ?> !important;
                transition: background-color 0.3s ease; 
            }
            html.front18-hide body { 
                opacity: 0 !important;
                visibility: hidden !important;
                pointer-events: none !important; 
                overflow: hidden !important; 
                touch-action: none !important; 
            }
            <?php endif; ?>
        </style>
        
        <script data-no-optimize="1" data-no-minify="1" data-cfasync="false" data-pagespeed-no-defer="1">
            (function(){
                if (!window.__front18_anti_flicker) {
                    window.__front18_anti_flicker = true;
                    document.documentElement.classList.add('front18-hide');
                }
            })();
        </script>

        <noscript>
            <style data-no-optimize="1" data-no-minify="1">
                html.front18-hide {
                    opacity: 1 !important;
                }
                html.front18-hide body {
                    pointer-events: auto !important;
                    overflow: auto !important;
                    touch-action: auto !important;
                }
            </style>
        </noscript>
        <?php
    }

    public function inject_sdk_loader() {
        if ( ! $this->should_run() ) return;

        $api_key       = get_option( 'front18_api_key', '' );
        $sdk_url       = get_option('front18_sdk_url', 'https://front18.com/public/sdk/front18.js');
        $global_object = get_option( 'front18_global_object', 'Front18' );
        $token_key     = get_option( 'front18_token_key', 'api-key' );
        $debug_mode    = get_option( 'front18_debug_mode', false );

        $synced_config = get_option( 'front18_synced_config', array() );
        $display_mode  = !empty($synced_config['display_mode']) ? $synced_config['display_mode'] : 'global_lock';
        $blur_amount   = isset($synced_config['blur_amount']) ? (int)$synced_config['blur_amount'] : 25;
        $blur_selector = !empty($synced_config['blur_selector']) ? $synced_config['blur_selector'] : 'img, video, iframe, [data-front18="locked"]';
        $color_bg      = !empty($synced_config['color_bg']) ? $synced_config['color_bg'] : '#0f172a';
        $color_text    = !empty($synced_config['color_text']) ? $synced_config['color_text'] : '#f8fafc';
        $color_primary = !empty($synced_config['color_primary']) ? $synced_config['color_primary'] : '#6366f1';

        $parsed_url = wp_parse_url($sdk_url);
        if ($parsed_url && isset($parsed_url['scheme'], $parsed_url['host'])) {
            $preconnect_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        } else {
            $preconnect_url = 'https://front18.com';
        }

        ?>
        <!-- FRONT18: PRECONNECT -->
        <link rel="preconnect" href="<?php echo esc_url($preconnect_url); ?>">
        <link rel="dns-prefetch" href="<?php echo esc_url($preconnect_url); ?>">

        <!-- FRONT18: CARREGADOR E METADADOS -->
        <script data-no-optimize="1" data-no-minify="1" data-cfasync="false" data-pagespeed-no-defer="1">
            // Prevenção contra múltiplos loads de SDK, Erros em SPAs, Turbolinks e Builders
            if (!window.__front18_loaded__) {
                window.__front18_loaded__ = Date.now();

                // 1. Estado Nível SaaS (Protegido contra sobrescrita)
                window.__front18_state__ = Object.assign({
                    locked: true,
                    released: false,
                    releaseReason: null,
                    startedAt: Date.now(),
                    sdkDetected: false,     // SDK deve atualizar isso imediatamente ao baixar
                    sdkInitialized: false,  // SDK deve atualizar isso ao botar pra rodar
                    url: location.href,
                    userAgent: navigator.userAgent
                }, window.__front18_state__ || {});

                // 2. Config Object Seguro (Com Contexto WP Completo para o Payload SaaS)
                <?php $synced_rules = get_option('front18_synced_rules', array()); ?>
                <?php
                $protected_media = get_option( 'front18_protected_media_ids', array() );
        
                $protected_urls = array();
                if ( is_array($protected_media) && !empty($protected_media) ) {
                    foreach ($protected_media as $id) {
                        $url = wp_get_attachment_url($id);
                        
                        // Ghost Dictionary Fallback: Se não achar na biblioteca do WP, procura na lista de fantasmas que rastreamos
                        if (!$url) {
                            $ghost_dict = get_option('front18_ghost_media_dict', array());
                            $url = isset($ghost_dict[$id]) ? $ghost_dict[$id] : false;
                        }

                        if ($url) {
                            $filename = basename($url);
                            $name_only = pathinfo($filename, PATHINFO_FILENAME);
                            // Remove sufixos padrão do WP (Ex: foto-1024x768 -> foto)
                            $base_name = preg_replace('/-[0-9]+x[0-9]+$/', '', $name_only);
                            $protected_urls[] = $base_name;
                        }
                    }
                }
                $protected_urls = array_values(array_unique($protected_urls));
                ?>
                // Front18 Shield Security Payload
                window.Front18Config = Object.assign({}, window.Front18Config || {}, {
                    apiKey: '<?php echo esc_js($api_key); ?>',
                    mode: '<?php echo esc_js($display_mode); ?>',
                    level: <?php echo isset($synced_config['level']) ? (int)$synced_config['level'] : 1; ?>,
                    blur_amount: <?php echo (int) $blur_amount; ?>,
                    blur_selector: '<?php echo esc_js($blur_selector); ?>',
                    theme: {
                        bg: '<?php echo esc_js($color_bg); ?>',
                        primary: '<?php echo esc_js($color_primary); ?>',
                        text: '<?php echo esc_js($color_text); ?>'
                    },
                    preventScroll: true,
                    whitelistRoutes: [],
                    protectRoutes: ['*'],
                    protected_media_ids: <?php echo wp_json_encode( is_array($protected_media) ? array_map('intval', $protected_media) : array() ); ?>,
                    protectedMediaNames: <?php echo wp_json_encode( $protected_urls ); ?>,
                    env: 'wordpress',
                    wpContext: {
                        postId: <?php echo intval( ( is_singular() && get_post() ) ? get_post()->ID : 0 ); ?>,
                        postType: '<?php echo esc_js( get_post_type() ? get_post_type() : "" ); ?>',
                        override: '<?php echo esc_js( ( is_singular() && get_post() ) ? (get_post_meta( get_post()->ID, "_front18_protect", true ) ?: "default") : "default" ); ?>',
                        globalScope: <?php echo !empty($synced_rules['global']) ? 'true' : 'false'; ?>
                    }
                });

                // 3. CORE INTERNO: Liberação Idempotente
                function _front18Unlock(reason) {
                    if (!window.__front18_state__.locked) return;

                    document.documentElement.classList.remove('front18-hide');
                    
                    window.__front18_state__.locked = false;
                    window.__front18_state__.released = true;
                    window.__front18_state__.releaseReason = reason;
                    window.__front18_state__.latency = Date.now() - window.__front18_state__.startedAt;
                    
                    var event;
                    if (typeof CustomEvent === "function") {
                        event = new CustomEvent('front18:released', {
                            detail: window.__front18_state__
                        });
                    } else {
                        event = document.createEvent('CustomEvent');
                        event.initCustomEvent('front18:released', true, true, window.__front18_state__);
                    }
                    document.dispatchEvent(event);
                }

                // 4. API ABERTA (O SDK DEVE usar este Contract Hook global para expor a DOM)
                var sdkName = '<?php echo esc_js($global_object); ?>' || 'Front18';
                var sdkReleaseFn = sdkName + 'Release';
                
                window[sdkReleaseFn] = window.Front18Release = function() {
                    _front18Unlock('sdk');
                    <?php if ( $debug_mode ) : ?>console.log(<?php echo wp_json_encode( __( '[Front18 CSS] DOM Liberado por SDK em ', 'front18' ) ); ?> + window.__front18_state__.latency + 'ms');<?php endif; ?>
                };

                // 5. Watchdog Estrito e Adaptativo Nível Netflix (Baseado na Rede)
                var maxTimeout = (navigator.connection && navigator.connection.effectiveType === '4g') ? 2000 : 3500;
                setTimeout(function() {
                    if (document.documentElement.classList.contains('front18-hide')) {
                        window.__front18_state__.failed = true;
                        
                        // Diagnóstico de Falha Nível Produto: Identifica 100% onde foi a quebra
                        if (!window.__front18_state__.sdkDetected) {
                            _front18Unlock('sdk_not_loaded');
                            <?php if ( $debug_mode ) : ?>console.warn(<?php echo wp_json_encode( __( '[Front18 Watchdog] CDN inalcançável. O SDK não pôde ser baixado na rede do cliente.', 'front18' ) ); ?>);<?php endif; ?>
                        } else if (!window.__front18_state__.sdkInitialized) {
                            _front18Unlock('sdk_fail');
                            <?php if ( $debug_mode ) : ?>console.warn(<?php echo wp_json_encode( __( '[Front18 Watchdog] SDK retornado, mas sofreu fatal error de inicialização interna.', 'front18' ) ); ?>);<?php endif; ?>
                        } else {
                            _front18Unlock('timeout');
                            <?php if ( $debug_mode ) : ?>console.warn(<?php echo wp_json_encode( __( '[Front18 Watchdog] Timeout adaptativo estourado.', 'front18' ) ); ?>);<?php endif; ?>
                        }
                    }
                }, maxTimeout);

                // 6. EXTERNAL SDK INJECTION (Controle Dinâmico Absoluto com Defer Estrito e Data Attributes Payload)
                var sdkScript = document.createElement('script');
                var cacheBuster = window.Front18Config.apiKey.substring(0,5) + '_' + Date.now().toString().substring(6);
                sdkScript.src = "<?php echo esc_url( $sdk_url ); ?>?v=" + cacheBuster;
                // Removemos o defer para garantir que a injeção inicie de imediato 
                // para o Watchdog do antiflicker registrar o SDK
                sdkScript.setAttribute('data-cfasync', 'false');
                sdkScript.setAttribute('data-no-optimize', '1');
                sdkScript.setAttribute('data-auto-init', 'true');
                sdkScript.setAttribute('data-<?php echo esc_attr( strtolower( $token_key ) ); ?>', '<?php echo esc_attr( $api_key ); ?>');
                document.head.appendChild(sdkScript);
            }
        </script>
        <?php
    }

    private function should_run() {
        if ( $this->should_run_cached !== null ) {
            return $this->should_run_cached;
        }
        $this->should_run_cached = $this->_calculate_scope();
        return $this->should_run_cached;
    }

    private function _calculate_scope() {
        if ( is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX) || is_feed() ) return false;
        if ( ! get_option( 'front18_enabled', false ) ) return false;
        
        $api_key = get_option( 'front18_api_key', '' );
        if ( empty( $api_key ) ) return false;

        $current_post = get_post();
        $current_id   = ( is_singular() && $current_post ) ? $current_post->ID : 0;

        // 0. Regra Soberana Nível Meta Box (Override Editando a Própria Página)
        if ( $current_id > 0 ) {
            $meta_override = get_post_meta( $current_id, '_front18_protect', true );
            if ( $meta_override === 'protect' ) return true;
            if ( $meta_override === 'unprotect' ) return false;
        }
        
        $exclude_ids = get_option( 'front18_exclude_ids', '' );
        if ( ! empty( $exclude_ids ) && $current_id > 0 ) {
            $exclude_arr = array_map( 'intval', explode( ',', $exclude_ids ) );
            if ( in_array( $current_id, $exclude_arr ) ) return false;
        }

        $include_ids = get_option( 'front18_include_ids', '' );
        if ( ! empty( $include_ids ) && $current_id > 0 ) {
            $include_arr = array_map( 'intval', explode( ',', $include_ids ) );
            if ( in_array( $current_id, $include_arr ) ) return true;
        }

        $synced_config = get_option('front18_synced_config', array());
        $display_mode = sanitize_text_field( $synced_config['display_mode'] ?? 'global_lock' );
        $protected_media = get_option( 'front18_protected_media_ids', array() );

        // 1. FORÇAMENTO GLOBAL INTELIGENTE (Matriz Granular Ativa = Auto-Injeção em Todo Site)
        // Se há imagens selecionadas na Matriz O SaaS EXIGE que o Shield cace-as em qualquer rota!
        if ( !empty($protected_media) && $display_mode === 'blur_media' ) {
            return true;
        }

        $synced_rules = get_option('front18_synced_rules', false);

        // Migração Silenciosa de Versão (Garante que sites antigos continuem protegidos ao atualizarem hoje)
        if ( $synced_rules === false ) {
            $synced_rules = array(
                'global' => get_option('front18_scope_global', false),
                'home'   => get_option('front18_scope_home', false),
                'cpts'   => array()
            );
            $all_cpts = get_post_types( array( 'public' => true ) );
            foreach ( $all_cpts as $cpt ) {
                if ( get_option('front18_scope_cpt_' . $cpt, false) ) {
                    $synced_rules['cpts'][] = $cpt;
                }
            }
            update_option('front18_synced_rules', $synced_rules);
        }

        if ( !empty($synced_rules['global']) ) return true;

        if ( is_front_page() || is_home() ) {
            if ( !empty($synced_rules['home']) ) return true;
        }

        $cpts = isset($synced_rules['cpts']) && is_array($synced_rules['cpts']) ? $synced_rules['cpts'] : array();

        if ( is_singular() ) {
            $post_type = get_post_type();
            if ( in_array($post_type, $cpts) ) return true;
        } else if ( is_archive() || is_post_type_archive() ) {
            $post_type = get_post_type();
            if ( $post_type && in_array($post_type, $cpts) ) return true;
        }

        return false;
    }

    public function render_shortcode( $atts ) {
        return '<div id="front18-inline" class="front18-sdk-rendered" style="display:none;"></div>';
    }

    public function render_lock_shortcode( $atts, $content = null ) {
        // Envolve o conteúdo apenas se estivemos no front-end real.
        if ( is_null( $content ) || is_admin() ) return $content;
        return '<div class="Front18-lock" data-front18="locked">' . do_shortcode( $content ) . '</div>';
    }
}
