# Export document mappings

Templates are loaded from **`Formats/Export/`** (relative to project root).

Currently used (2 files):

- **Commercial Invoice:** set in `commercial_invoice.php` → `'template_file' => 'Formats/Export/YourFileName.xlsx'`
- **Packing List:** set in `packing_list.php` → `'template_file' => 'Formats/Export/YourFileName.xlsx'`

If your Excel filenames are different, edit the `template_file` in each `.php` file above. Adjust cell references in the same files to match your Excel layout.
