<?php
function innerplay_register_post_type_gioco()
{
    $labels = array(
        'name'               => 'Giochi',
        'singular_name'      => 'Gioco',
        'menu_name'          => 'Giochi',
        'name_admin_bar'     => 'Gioco',
        'add_new'            => 'Aggiungi Nuovo',
        'add_new_item'       => 'Aggiungi Nuovo Gioco',
        'new_item'           => 'Nuovo Gioco',
        'edit_item'          => 'Modifica Gioco',
        'view_item'          => 'Visualizza Gioco',
        'all_items'          => 'Tutti i Giochi',
        'search_items'       => 'Cerca Giochi',
        'not_found'          => 'Nessun gioco trovato',
        'not_found_in_trash' => 'Nessun gioco nel cestino'
    );

    $args = array(
        'labels'             => $labels,
        'description'        => 'Contenuti per i giochi Unity',
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'menu_icon'          => 'dashicons-games',
        'query_var'          => true,
        'rewrite'            => array('slug' => 'giochi'),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => 20,
        'supports'           => array('title', 'editor', 'thumbnail'),
		 'taxonomies'         => array('categoria_giochi'),
        'show_in_rest'       => true,
		
    );

    register_post_type('gioco', $args);
}
add_action('init', 'innerplay_register_post_type_gioco');

function ipt_registra_categoria_giochi() {
    register_taxonomy(
        'categoria_giochi',
        'gioco',
        array(
            'labels' => array(
                'name'              => 'Categorie Giochi',
                'singular_name'     => 'Categoria Gioco',
                'search_items'      => 'Cerca Categoria',
                'all_items'         => 'Tutte le Categorie',
                'edit_item'         => 'Modifica Categoria',
                'update_item'       => 'Aggiorna Categoria',
                'add_new_item'      => 'Aggiungi Nuova Categoria',
                'new_item_name'     => 'Nome Nuova Categoria',
                'menu_name'         => 'Categorie Giochi',
            ),
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'rewrite'           => array('slug' => 'categoria-giochi'),
			'show_in_rest' => true,
        )
    );
}
add_action('init', 'ipt_registra_categoria_giochi');

add_action('init', function() {
    register_taxonomy_for_object_type('categoria_giochi', 'gioco');
});