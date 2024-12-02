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
            // Your logic here
        }

        /**
         * Create sample data for Decker Plugin.
         *
         * This command creates 10 labels, 5 boards and 2 tasks per board.
         *
         * ## EXAMPLES
         *
         *     wp decker create_sample_data
         *
         */
        public function create_sample_data() {
            // Temporarily elevate permissions
            $current_user = wp_get_current_user();
            $old_user = $current_user;
            wp_set_current_user(1); // Switch to admin user (ID 1)
            
            WP_CLI::log("Starting sample data creation...");

            // 1. Create labels
            WP_CLI::log("Creating labels...");
            $labels = [];
            for ($i = 1; $i <= 10; $i++) {
                $term_name = "Label $i";
                $term_slug = sanitize_title($term_name);
                $term_color = $this->generate_random_color();

                // Verificar si la etiqueta ya existe
                $existing_term = term_exists($term_slug, 'decker_label');
                if ($existing_term) {
                    WP_CLI::warning("Label '$term_name' already exists. Skipping...");
                    $labels[] = $existing_term['term_id'];
                    continue;
                }

                $term = wp_insert_term($term_name, 'decker_label', [
                    'slug' => $term_slug,
                ]);

                if (is_wp_error($term)) {
                    WP_CLI::warning("Error creating label '$term_name': " . $term->get_error_message());
                    continue;
                }

                // Añadir meta 'term_color'
                add_term_meta($term['term_id'], 'term_color', $term_color, true);
                WP_CLI::success("Label '$term_name' created with color $term_color.");
                $labels[] = $term['term_id'];
            }

            // 2. Create boards
            WP_CLI::log("Creating boards...");
            $boards = [];
            for ($i = 1; $i <= 5; $i++) {
                $term_name = "Board $i";
                $term_slug = sanitize_title($term_name);
                $term_color = $this->generate_random_color();

                // Verificar si el tablero ya existe
                $existing_term = term_exists($term_slug, 'decker_board');
                if ($existing_term) {
                    WP_CLI::warning("Board '$term_name' already exists. Skipping...");
                    $boards[] = $existing_term['term_id'];
                    continue;
                }

                $term = wp_insert_term($term_name, 'decker_board', [
                    'slug' => $term_slug,
                ]);

                if (is_wp_error($term)) {
                    WP_CLI::warning("Error creating board '$term_name': " . $term->get_error_message());
                    continue;
                }

                // Añadir meta 'term_color'
                add_term_meta($term['term_id'], 'term_color', $term_color, true);
                WP_CLI::success("Board '$term_name' created with color $term_color.");
                $boards[] = $term['term_id'];
            }

            // 3. Get all users
            WP_CLI::log("Getting users...");
            $users = get_users(['fields' => ['ID']]);
            if (empty($users)) {
                WP_CLI::error("No users available to assign to tasks.");
                return;
            }
            $user_ids = wp_list_pluck($users, 'ID');

            // 4. Create tasks
            WP_CLI::log("Creating tasks...");
            foreach ($boards as $board_id) {
                $board = get_term($board_id, 'decker_board');
                if (is_wp_error($board)) {
                    WP_CLI::warning("Could not get board with ID $board_id. Skipping...");
                    continue;
                }

                for ($j = 1; $j <= 2; $j++) {
                    $post_title = "Task $j for {$board->name}";
                    $post_content = "Content for task $j in board {$board->name}.";

                    // Crear la tarea
                    $post_id = wp_insert_post([
                        'post_title'   => $post_title,
                        'post_content' => $post_content,
                        'post_status'  => 'publish',
                        'post_type'    => 'decker_task',
                    ]);

                    if (is_wp_error($post_id)) {
                        WP_CLI::warning("Error creating task '$post_title': " . $post_id->get_error_message());
                        continue;
                    }

                    // Asignar el tablero a la tarea
                    wp_set_object_terms($post_id, [$board_id], 'decker_board');

                    // Asignar etiquetas aleatorias (0 a 3 etiquetas)
                    $num_labels = rand(0, 3);
                    if ($num_labels > 0 && !empty($labels)) {
                        $assigned_labels = $this->wp_rand_elements($labels, $num_labels);
                        wp_set_object_terms($post_id, $assigned_labels, 'decker_label');
                    }

                    // Asignar usuarios aleatorios (1 a 3 usuarios)
                    $num_users = rand(1, 3);
                    $assigned_users = $this->wp_rand_elements($user_ids, $num_users);
                    update_post_meta($post_id, 'assigned_users', $assigned_users);

                    WP_CLI::success("Task '$post_title' created and assigned to board '{$board->name}'.");
                }
            }

            WP_CLI::success("Sample data created successfully!");
            
            // Restore original user
            wp_set_current_user($old_user->ID);
        }

        /**
         * Generates a random hexadecimal color.
         *
         * @return string Color in hexadecimal format (e.g., #a3f4c1).
         */
        private function generate_random_color() {
            return sprintf('#%06X', mt_rand(0, 0xFFFFFF));
        }

        /**
         * Selects random elements from an array.
         *
         * @param array $array Array to select elements from.
         * @param int   $number Number of elements to select.
         * @return array Selected elements.
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
