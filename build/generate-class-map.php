<?php
/**
 * Build Script: Generate Class Map for Access Lens (PML).
 * This script scans the plugin's 'includes' directory
 * and generates a pml-class-map.php file for optimized autoloading.
 * The paths in the generated map will be relative to the map file's directory (includes/).
 * How to run:
 * 1. Place this script in a 'build' directory in your plugin's root.
 * (e.g., wp-content/plugins/protected-media-links/build/generate-pml-class-map.php)
 * 2. Ensure PML_PLUGIN_DIR_FOR_BUILD is correctly defined below.
 * 3. Run from the command line: php build/generate-pml-class-map.php
 *
 * @package AccessLensBuild
 */

echo "Starting Access Lens (PML) class map generation (PHP 7.4 Compatible)...\n";

// Define the plugin directory. Adjust if this script is placed elsewhere.
if ( !defined( 'PML_PLUGIN_DIR_FOR_BUILD' ) )
{
    // Assumes this script is in 'wp-plugin-root/build/'
    define( 'PML_PLUGIN_DIR_FOR_BUILD', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}

$plugin_dir = realpath( PML_PLUGIN_DIR_FOR_BUILD );
if ( !$plugin_dir )
{
    echo "Error: Could not determine plugin directory from PML_PLUGIN_DIR_FOR_BUILD: " . PML_PLUGIN_DIR_FOR_BUILD . "\n";
    exit( 1 );
}
$plugin_dir .= DIRECTORY_SEPARATOR;

// Directory to scan within the plugin (relative to $plugin_dir).
// PML classes are primarily in 'includes/' and its subdirectories like 'includes/utilities/'.
$scan_target_relative = 'includes' . DIRECTORY_SEPARATOR;
$scan_dir_abs         = realpath( $plugin_dir . $scan_target_relative );

// Directories to explicitly exclude from scanning (relative to $plugin_dir).
$exclude_directories_relative = [
    'vendor' . DIRECTORY_SEPARATOR,       // Standard Composer vendor directory
    'build' . DIRECTORY_SEPARATOR,        // This build script's directory
    'admin' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR, // Admin assets, not classes
    'assets' . DIRECTORY_SEPARATOR,       // General public assets
    'languages' . DIRECTORY_SEPARATOR,    // Translation files
    'node_modules' . DIRECTORY_SEPARATOR, // Node.js dependencies
    // Add any other directories that don't contain plugin classes.
];

// Output file for the class map.
// This will be placed in the 'includes' directory.
$output_file_relative = 'includes' . DIRECTORY_SEPARATOR . 'pml-class-map.php';
$output_file_abs      = $plugin_dir . $output_file_relative;
$class_map_output_dir = dirname( $output_file_abs ); // Absolute path to 'includes' directory

$class_map = [];

echo "Plugin directory for build: " . $plugin_dir . "\n";
echo "Scanning for classes in: " . $scan_dir_abs . "\n";
echo "Class map will be generated at: " . $output_file_abs . "\n";
echo "Paths in map will be relative to: " . $class_map_output_dir . "\n";

if ( !$scan_dir_abs || !is_dir( $scan_dir_abs ) )
{
    echo "Error: Directory to scan not found or not accessible: " . $plugin_dir . $scan_target_relative . "\n";
    exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $scan_dir_abs, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY,
);

/** @var SplFileInfo $file */
foreach ( $iterator as $file )
{
    $file_path_abs = $file->getRealPath();

    // Skip if not a PHP file.
    if ( strtolower( $file->getExtension() ) !== 'php' )
    {
        continue;
    }

    // Check against exclude directories.
    $skip_file = false;
    foreach ( $exclude_directories_relative as $exclude_dir_relative_to_plugin )
    {
        $excluded_path_abs = realpath( $plugin_dir . $exclude_dir_relative_to_plugin );
        if ( $excluded_path_abs && stripos( $file_path_abs, $excluded_path_abs . DIRECTORY_SEPARATOR ) === 0 )
        {
            $skip_file = true;
            break;
        }
    }

    if ( $skip_file )
    {
        // echo "Skipping (excluded): " . $file_path_abs . "\n";
        continue;
    }

    $content          = file_get_contents( $file_path_abs );
    $tokens           = token_get_all( $content );
    $namespace        = ''; // PML plugin does not use PHP namespaces, classes are prefixed.
    $class_like_found = false;

    for ( $i = 0; $i < count( $tokens ); $i++ )
    {
        if ( is_array( $tokens[ $i ] ) )
        {
            switch ( $tokens[ $i ][ 0 ] )
            {
                case T_NAMESPACE:
                    $namespace = '';
                    for ( $j = $i + 1; $j < count( $tokens ); $j++ )
                    {
                        // For PHP 7.4 compatibility, remove T_NAME_QUALIFIED
                        // Namespaces are built from T_STRING and T_NS_SEPARATOR in PHP < 8.0
                        if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][ 0 ], [ T_STRING, T_NS_SEPARATOR ], true ) )
                        {
                            $namespace .= $tokens[ $j ][ 1 ];
                        }
                        elseif ( $tokens[ $j ] === ';' )
                        {
                            $i = $j;
                            break;
                        }
                    }
                    break;

                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                    // T_ENUM requires PHP 8.1+ for the tokenizer. Add if supporting PHP 8.1+.
                    // if (defined('T_ENUM') && $tokens[$i][0] === T_ENUM) $class_like_found = true;
                    $class_like_found = true;
                    break;

                case T_STRING:
                    if ( $class_like_found )
                    {
                        $class_name = $tokens[ $i ][ 1 ];
                        $full_name  = $namespace ? $namespace . '\\' . $class_name : $class_name;

                        if ( strpos( $full_name, 'PML_' ) === 0 )
                        {
                            if ( isset( $class_map[ $full_name ] ) &&
                                 realpath( $class_map_output_dir . DIRECTORY_SEPARATOR . $class_map[ $full_name ] ) !== $file_path_abs )
                            {
                                echo "Warning: Duplicate class definition found for '$full_name'. Check files:\n";
                                echo "  - Mapped to: " . $class_map_output_dir . DIRECTORY_SEPARATOR . $class_map[ $full_name ] . "\n";
                                echo "  - Current file: " . $file_path_abs . "\n";
                            }

                            $path_for_map_entry = $file_path_abs;
                            if ( stripos( $path_for_map_entry, $class_map_output_dir . DIRECTORY_SEPARATOR ) === 0 )
                            {
                                $path_for_map_entry = substr( $path_for_map_entry, strlen( $class_map_output_dir . DIRECTORY_SEPARATOR ) );
                            }
                            else
                            {
                                echo "Warning: File $file_path_abs is unexpectedly outside the class map directory $class_map_output_dir.\n";
                                echo "         Storing path relative to plugin root instead, but this might need review.\n";
                                if ( stripos( $path_for_map_entry, $plugin_dir ) === 0 )
                                {
                                    $path_for_map_entry = substr( $path_for_map_entry, strlen( $plugin_dir ) );
                                }
                            }

                            $path_for_map_entry      = str_replace( DIRECTORY_SEPARATOR, '/', $path_for_map_entry );
                            $class_map[ $full_name ] = $path_for_map_entry;
                            echo "Found: {$full_name} -> {$path_for_map_entry}\n";
                        }
                        $class_like_found = false;
                    }
                    break;
            }
        }
    }
}

if ( empty( $class_map ) )
{
    echo "No classes matching the prefix 'PML_' found in '" .
         $scan_target_relative .
         "'. Please check scan directory, file contents, and plugin structure.\n";
}
else
{
    ksort( $class_map );
    $output_content = "<?php\n";
    $output_content .= "// Access Lens (PML) Class Map - Auto-generated by build/generate-pml-class-map.php\n";
    $output_content .= "// Plugin: Access Lens\n";
    $output_content .= "// Do not edit this file manually.\n\n";
    $output_content .= "return [\n";
    foreach ( $class_map as $class => $path_in_map )
    {
        $output_content .= "\t'" . addslashes( $class ) . "' => __DIR__ . '/" . addslashes( $path_in_map ) . "',\n";
    }
    $output_content .= "];\n";

    if ( !is_dir( $class_map_output_dir ) )
    {
        if ( !mkdir( $class_map_output_dir, 0755, true ) )
        {
            echo "Error: Could not create directory for class map: " . $class_map_output_dir . "\n";
            exit( 1 );
        }
    }

    if ( file_put_contents( $output_file_abs, $output_content ) )
    {
        echo "Class map generated successfully at: " . $output_file_abs . "\n";
        echo count( $class_map ) . " classes, interfaces, and traits mapped.\n";
    }
    else
    {
        echo "Error: Could not write class map to: " . $output_file_abs . "\n";
        exit( 1 );
    }
}

echo "PML class map generation finished.\n";
