(function() {
  tinymce.PluginManager.add('checklist', function(editor, url) {
    // Add a button that inserts a checklist shortcode.
    editor.addButton('checklist', {
      text: 'Checklist',
      icon: false,
      onclick: function() {
        // Insert a checklist shortcode into the editor.
        editor.insertContent('[checklist][item]Task 1[/item][item]Task 2[/item][/checklist]');
      }
    });
  });
})();
