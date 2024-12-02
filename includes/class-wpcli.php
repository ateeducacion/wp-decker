<?php
// Decker_WPCLI.php
if (defined('WP_CLI') && WP_CLI) {

    /**
     * Custom WP-CLI commands for Decker Plugin.
     */
    class Decker_WPCLI extends WP_CLI_Command {

        /**
         * Say hello.
         *
         * ## OPTIONS
         *
         * [--name=<name>]
         * : The name to greet.
         *
         * ## EXAMPLES
         *
         *     wp decker greet --name=Freddy
         *
         * @param array $args Positional arguments.
         * @param array $assoc_args Associative arguments.
         */
        public function greet($args, $assoc_args) {
            $name = $assoc_args['name'] ?? 'World';
            WP_CLI::success("Hello, $name!");
        }

        /**
         * Do something else.
         *
         * ## EXAMPLES
         *
         *     wp decker do-something
         *
         */
        public function do_something() {
            WP_CLI::log("Doing something...");
            // Tu lógica aquí
        }

        /**
         * Crear datos de prueba para Decker Plugin.
         *
         * Este comando crea 10 etiquetas, 5 tableros y 2 tareas por cada tablero.
         *
         * ## EXAMPLES
         *
         *     wp decker create_sample_data
         *
         */
        public function create_sample_data() {
            WP_CLI::log("Iniciando la creación de datos de prueba...");

            // 1. Crear etiquetas
            WP_CLI::log("Creando etiquetas...");
            $labels = [];
            for ($i = 1; $i <= 10; $i++) {
                $term_name = "Etiqueta $i";
                $term_slug = sanitize_title($term_name);
                $term_color = $this->generate_random_color();

                // Verificar si la etiqueta ya existe
                $existing_term = term_exists($term_slug, 'decker_label');
                if ($existing_term) {
                    WP_CLI::warning("La etiqueta '$term_name' ya existe. Saltando...");
                    $labels[] = $existing_term['term_id'];
                    continue;
                }

                $term = wp_insert_term($term_name, 'decker_label', [
                    'slug' => $term_slug,
                ]);

                if (is_wp_error($term)) {
                    WP_CLI::warning("Error al crear la etiqueta '$term_name': " . $term->get_error_message());
                    continue;
                }

                // Añadir meta 'term_color'
                add_term_meta($term['term_id'], 'term_color', $term_color, true);
                WP_CLI::success("Etiqueta '$term_name' creada con color $term_color.");
                $labels[] = $term['term_id'];
            }

            // 2. Crear tableros
            WP_CLI::log("Creando tableros...");
            $boards = [];
            for ($i = 1; $i <= 5; $i++) {
                $term_name = "Tablero $i";
                $term_slug = sanitize_title($term_name);
                $term_color = $this->generate_random_color();

                // Verificar si el tablero ya existe
                $existing_term = term_exists($term_slug, 'decker_board');
                if ($existing_term) {
                    WP_CLI::warning("El tablero '$term_name' ya existe. Saltando...");
                    $boards[] = $existing_term['term_id'];
                    continue;
                }

                $term = wp_insert_term($term_name, 'decker_board', [
                    'slug' => $term_slug,
                ]);

                if (is_wp_error($term)) {
                    WP_CLI::warning("Error al crear el tablero '$term_name': " . $term->get_error_message());
                    continue;
                }

                // Añadir meta 'term_color'
                add_term_meta($term['term_id'], 'term_color', $term_color, true);
                WP_CLI::success("Tablero '$term_name' creado con color $term_color.");
                $boards[] = $term['term_id'];
            }

            // 3. Obtener todos los usuarios
            WP_CLI::log("Obteniendo usuarios...");
            $users = get_users(['fields' => ['ID']]);
            if (empty($users)) {
                WP_CLI::error("No hay usuarios disponibles para asignar a las tareas.");
                return;
            }
            $user_ids = wp_list_pluck($users, 'ID');

            // 4. Crear tareas
            WP_CLI::log("Creando tareas...");
            foreach ($boards as $board_id) {
                $board = get_term($board_id, 'decker_board');
                if (is_wp_error($board)) {
                    WP_CLI::warning("No se pudo obtener el tablero con ID $board_id. Saltando...");
                    continue;
                }

                for ($j = 1; $j <= 2; $j++) {
                    $post_title = "Tarea $j para {$board->name}";
                    $post_content = "Contenido de la tarea $j para el tablero {$board->name}.";

                    // Crear la tarea
                    $post_id = wp_insert_post([
                        'post_title'   => $post_title,
                        'post_content' => $post_content,
                        'post_status'  => 'publish',
                        'post_type'    => 'decker_task',
                    ]);

                    if (is_wp_error($post_id)) {
                        WP_CLI::warning("Error al crear la tarea '$post_title': " . $post_id->get_error_message());
                        continue;
                    }

                    // Asignar el tablero a la tarea
                    wp_set_object_terms($post_id, [$board_id], 'decker_board');

                    // Asignar etiquetas aleatorias (0 a 3 etiquetas)
                    $num_labels = rand(0, 3);
                    if ($num_labels > 0 && !empty($labels)) {
                        $assigned_labels = wp_rand_elements($labels, $num_labels);
                        wp_set_object_terms($post_id, $assigned_labels, 'decker_label');
                    }

                    // Asignar usuarios aleatorios (1 a 3 usuarios)
                    $num_users = rand(1, 3);
                    $assigned_users = wp_rand_elements($user_ids, $num_users);
                    update_post_meta($post_id, 'assigned_users', $assigned_users);

                    WP_CLI::success("Tarea '$post_title' creada y asignada al tablero '{$board->name}'.");
                }
            }

            WP_CLI::success("¡Datos de prueba creados exitosamente!");
        }

        /**
         * Genera un color hexadecimal aleatorio.
         *
         * @return string Color en formato hexadecimal (e.g., #a3f4c1).
         */
        private function generate_random_color() {
            return sprintf('#%06X', mt_rand(0, 0xFFFFFF));
        }

        /**
         * Selecciona elementos aleatorios de un array.
         *
         * @param array $array Array del cual seleccionar elementos.
         * @param int   $number Número de elementos a seleccionar.
         * @return array Elementos seleccionados.
         */
        private function wp_rand_elements($array, $number) {
            if ($number >= count($array)) {
                return $array;
            }
            $keys = array_rand($array, $number);
            if ($number == 1) {
                return [$array[$keys]];
            }
            $selected = [];
            foreach ($keys as $key) {
                $selected[] = $array[$key];
            }
            return $selected;
        }
    }

    // Registrar el comando principal que agrupa los subcomandos
    WP_CLI::add_command('decker', 'Decker_WPCLI');
}
