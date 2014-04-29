<?php

namespace attitude\Mustache;

use \attitude\Elements\HTTPException;
use \attitude\Elements\DependencyContainer;

/**
 * Macaw template loader class
 *
 * Allow use of Macaw exports as mustache templates.
 *
 * Includes [mustache sections](http://mustache.github.io/mustache.5.html#Sections)
 *
 * - variables, e.g.: Welcome [user.name]
 * - false values via tag class, e.g.: `<img class=".only-if-value" >`
 * - repeatable sections (non-empty lists) via tag class, e.g.:
 *   ```
 *   <ul class=".repeat-person-in-event-attendants">
 *     <li>[firstName] [lastName]</li>
 *   </ul>
 *   or
 *   <ul class=".repeat-event-attendants">
 *     <li>[firstName] [lastName]</li>
 *   </ul>
 *   ```
 *
 * Non-mustache extras:
 *
 * - optional wrap with section: `{{#hasItems}}{{#items}}...{{/items}}{{/hasItems}}`
 *   which can be disabled by `\attitude\Elements\DependencyContainer::set('Macaw::useHasSections', false);`
 * - template translations (i18n & l10n); see `transcriptTranlations()` method
 *
 * Note on using classes in Macaw
 *
 * Macaw allows classes to be ONLY [a-z-_]. To support at least camelCase naming
 * convention, mark any variable context using '_' as prefix and suffix. Example:
 *
 * `p.text.only-if-event-_is_marked__marked_as_going_` is converted into
 * `p.text.only-if-event-isMarked_markedAsGoing`
 *
 * Anytime you use double `__` it is preserved as single '_'.
 *
 * Tip: By default Publisher supports Markdown, therefore you can use
 *      [variable | markdown] in your syntax. Just make sure, it is no inside
 *      a `<p>` tag using the Macaw's outline panel.
 *
 * @author Martin Adamko <http://twitter.com/martin_adamko>
 *
 */
class AtomicLoader_MacawLoader extends AtomicLoader_FilesystemLoader
{
    protected function getFileName($name)
    {
        static $allowed_folders = array('views', 'sections');

        if (strstr($name, '/')) {
            $name = explode('/', $name);
            $folder = array_shift($name);

            if (!in_array($folder, $allowed_folders)) {
                throw new \Mustache_Exception_RuntimeException('Macaw Loader can load either full page HTML (view) or contents of <body> element (section).' );
            }

            $name = implode('-', $name);
        }

        // Find all mcw files
        static $mcw_files = null;

        // Where to look for .mcw files
        static $mcw_baseDir = null;

        // Search just once
        if ($mcw_files===null) {
            $mcw_baseDir = dirname($this->baseDir);
            $mcw_files = array();

            // Lookup published exports
            if ($mcw_source_files = glob($mcw_baseDir.'/*.mcw')) {
                foreach ($mcw_source_files as $mcw_file) {
                    $export = pathinfo($mcw_file, PATHINFO_FILENAME);

                    if ($html_files = glob($mcw_baseDir."/{$export}/*.html")) {
                        foreach ($html_files as $html_file) {
                            // Add to array since more version could be available (more .mcw exports)
                            $mcw_files['html'][pathinfo($html_file, PATHINFO_FILENAME)][] = $html_file;
                        }
                    }

                    // Lookup published assets
                    foreach ($this->assets as &$asset) {
                        $ext = pathinfo($asset['glob'], PATHINFO_EXTENSION);

                        if ($asset_files = glob($mcw_baseDir."/{$export}/{$ext}/{$asset['glob']}")) {
                            foreach ($asset_files as $asset_file) {
                                $relative_url = str_replace($this->publicDir, $this->publicURL, $asset_file);
                                $mcw_files['assets'][$ext][] = sprintf($asset['template'], $relative_url);
                            }
                        }
                    }
                }
            }
        }

        // Partial matches Macaw .html export filename
        if (isset($mcw_files['html'][$name])) {
            foreach ($mcw_files['html'][$name] as $html_file) {
                $html_file_mtime = filemtime($html_file);
                $mustache_file = "{$this->baseDir}/{$folder}/{$name}/{$this->basename}{$this->extension}";

                // Mustache already cached and newer
                if (file_exists($mustache_file) && filemtime($mustache_file) > $html_file_mtime) {
                    // continue;
                }

                $html = $this->enhanceMacaw($html_file)."\n";

                if ($folder==='sections') {
                    $body_start = strpos($html, '<body');
                    if ($body_start!==false) {
                        if ($body_start = strpos($html, '>', $body_start)) {
                            if ($body_end = strpos($html, '</body>')) {
                                $l = $body_end - strlen($html);
                            } else {
                                $l = false;
                            }

                            $html = trim($l ? substr($html, ($body_start+1), $l) : substr($html, ($body_start+1)), "\n");
                        } else {
                            throw new HTTPException(500, 'The <body> content of Macaw export is zero length.');
                        }
                    }
                }

                // Append assets (Concatenate class takes care for duplicates)
                foreach ($mcw_files['assets'] as $type => $asset_files) {
                    $html.= implode("\n", $asset_files);
                }

                // Crete structure if not exists
                @mkdir(dirname($mustache_file), 0755, true);
                file_put_contents($mustache_file, $html);
            }
        }

        return parent::getFileName("{$folder}/{$name}");
    }

    /**
     * Replaces any variables in node text or in node attributes
     *
     * Examples:
     * - <input type="text" placeholder="[value]" />
     * - Some text node with [variable]
     */
    protected function transcriptToMustache($str, $feature=null)
    {
        if (empty($feature)) {
            $feature = array('item' => '');
        }

        return preg_replace_callback('/\[([\.\w]+(?:\s*\|\s*[-_\w]+)?)\]/', function($matches) use ($feature) {
            $matches = explode('.', $matches[1]);

            // Remove optional reference from actual context
            if ($feature['item']===$matches[0]) {
                array_shift($matches);
            }

            return str_replace('{{.', '{{', '{{'. implode('.', $matches) .'}}');
        }, $str);
    }

    /**
     * Replace visually nice markup from Macaw
     *
     * Note: This not Mustache supported format, but the parent class
     * `AtomicLoader_FilesystemLoader` is able to generate translation
     * `{{#__}}...{{/__}}` and `{{#_n}}...{{/_n}}` landas/helpers which
     * are further processed by automatically registered helpers of
     * `DAtaPreprocessor_Component` (Mustache wrapper).
     *
     * Usage in Macaw:
     *
     * - simple string translation: ^String to translate^
     * - pluralisation: ^counter ? One string : {} strings^
     *
     * This method understands most of the possibilities of multiple `?` and `:`
     * in the string. However, if it fails, escape any `:` as `\:`. Only first
     * '?' is considered important.
     *
     * If you are ever in need of using `^` character, just use `\^` instead.
     *
     * @param  string $str HTML string
     * @return string      Modified HTML string
     *
     */
    protected function transcriptTranlations($str)
    {
        // Being benevolent here to allow designers to "write it pretty" untill necessary
        return str_replace(
            array('&#63;', '&#58;'),
            array('?', ':'),
            preg_replace_callback('/\^[^\^]+?\^/', function($match){
                $match = trim($match[0], ' ^');

                // Pluralisation: Fix multiple '?'
                if (substr_count($match, '?')) {
                    // Only the first '?' is vital
                    $origpos = strpos($match, '?');
                    $match[$origpos] = '^';
                    $match = str_replace('?', '&#63;', $match);
                    $match[$origpos] = '?';

                    list($match, $expression) = explode('?', $match);
                    $expression = trim($expression);

                    $expressions = array('', '');

                    // Tried to use quoted string
                    if ($expression[0]==='\'' || $expression[0]==="\"") {
                        $q = $expression[0];
                        $expression_len = strlen($expression);
                        $next = 1;

                        // Find next unescaped quote
                        while(($expression[$next]!==$q && $next!==false)) {
                            $next = strpos($expression, $q, $next);

                            if ($expression[($next-1)]==='\\') {
                                $next++;
                            }
                        }

                        $expressions[0] = substr($expression, 1, ($next-1));
                        $expression = trim(substr($expression, ($next+1)));

                        // Ignore the rest if missing ':'
                        if ($expression[0]!==':') {
                            $expressions[1] = $expressions[0];
                        } else {
                                          // Escape back
                            $expression = str_replace($q, "\\{$q}",
                                // Unescape all
                                str_replace(
                                    array('\\\'', "\\\""),
                                    array('\'', "\""),
                                    // Must use same quotes, so trim anyway
                                    trim($expression, ' :'.$q)
                                )
                            );

                            $expressions[1] = $expression;
                        }
                    } else {
                        if (strstr($expression, ' : ')) {
                            $expression = explode(' : ', $expression);
                        } else {
                            // Fix multiple ':'
                            if (substr_count($expression, ':') > 1) {
                                // Escaped
                                $expression = str_replace('\\:', '&#58;', $expression);

                                if (substr_count($expression, ':') > 1) {
                                    // Imediately after word
                                    $expression = preg_replace('/([^ ]):/', '$1&#58;', $expression);
                                }

                                if (substr_count($expression, ':') > 1) {
                                    // Keep just the first one
                                    $origpos = strpos($expression, ':');
                                    $expression[$origpos] = '^';
                                    $expression = str_replace(':', '&#58;', $expression);
                                    $expression[$origpos] = ':';
                                }
                            }

                            $expression = explode(':', $expression);
                        }

                        if (count($expression)===1) {
                            $expression[1] = $expression[0];
                        } else {
                            // Tried to escape second string?
                            if ($expression[1][0]==='\'' || $expression[1][0]==="\"") {
                                $q = $expression[1][0];
                            } else {
                                $q = '\'';
                            }

                                        // Escape back
                            $expression[1] = str_replace($q, "\\{$q}",
                              // Unescape all
                              str_replace(
                                  array('\\\'', "\\\""),
                                  array('\'', "\""),
                                  // Must use same quotes, so trim anyway
                                  trim($expression[1], ' :'.$q)
                              )
                            );
                        }
                        $expressions = $expression;
                    }

                    if (!isset($q)) { $q = '\''; }

                    $expression = $q.$expressions[0].$q.' : '.$q.$expressions[1].$q;

                    return "{{{$match} ? {$expression}}} | translate";
                }

                // Tried to escape this string?
                if ($match[0]==='\'' || $match[0]==="\"") {
                    $q = $match[0];
                } else {
                    $q = '\'';
                }

                            // Escape back
                $match = str_replace($q, "\\{$q}",
                  // Unescape all
                  str_replace(
                      array('\\\'', "\\\""),
                      array('\'', "\""),
                      // Must use same quotes, so trim anyway
                      trim($match, ' :'.$q)
                  )
                );

                return '{{'.$q.$match.$q.' | translate}}';
            }, str_replace('\\^', '&#94;', $str)
        ));
    }

    /**
     * Quick and dirty way to enhance exported Macaw HTML
     */
    protected function enhanceMacaw($html_file)
    {
        // Split HTML string: tag / text node as array item
        $html = explode("))((", str_replace(array(
                '>', '<'
            ), array(
                '>))((', '))((<',
            ), file_get_contents($html_file))
        );

        /**
         * Looking for any:
         *
         * - `class="events.repeat-event-in-events"`
         * - `class="events.repeat-event-in-parent-events"`
         * - `class="title.only-if-title"`
         * - `class="title.only-if-parent-title"`
         */
        $lookups = array('repeat', 'only-if');
        $lookup_class_regex = '('.implode('|', $lookups).')-([^\s]+)';

        /**
         * Helper to check array needles against the haystack
         *
         * @param $haystack string The input string
         */
        function strarray($haystack, array $needles, $match_any=false)
        {
            foreach ($needles as &$needle) {
                $found = !! strstr($haystack, $needle);

                // Logical AND
                if ($match_any && $found===true) {
                    return true;
                }

                // Logical OR
                if (!$match_any && $found===false) {
                    return false;
                }
            }

            return ! $match_any;
        }

        $out       = array();
        $feature   = null;
        $open      = array();
        $last      = array();

        // Source: http://www.w3.org/TR/html5/syntax.html#void-elements
        $void_tags = array("area", "base", "br", "col", "embed", "hr", "img", "input", "keygen", "link", "meta", "param", "source", "track", "wbr");
        foreach ($html as $i => &$fragment) {
            // echo "\n\n".str_pad($i, 5, ' ', STR_PAD_LEFT). $fragment;
            // echo "\nOpen ";print_r($open);

            if (strarray($fragment, array('<img','"images/'))) {
                $fragment = str_replace('"images/', '"'.str_replace($this->publicDir, $this->publicURL, dirname($html_file)).'/images/', $fragment);
            }

            // Open tag; Using strstr() which is faster than regex first
            if (strarray($fragment, $lookups, true) && preg_match('/class=[\'"].*?'.$lookup_class_regex.'.*?[\'"]/', $fragment, $class)) {
                $feature = array(
                    'tag'      => null,
                    'name'     => $class[1],
                    // 'original' => $class[1].'-'.$class[2],
                    'item'     => '',
                    'context'  => ''
                );

                $lookup = $class[1];
                $class = str_replace($lookups, '', $class[2]);

                if (strstr($class, '-in-')) {
                    list($feature['item'], $feature['context']) = explode('-in-', $class);
                } else {
                    $feature['context'] = $class;
                }

                // Context stack

                // Marked as camelCase by class prefixed an suffixed with '_'
                // If you ever need to mix camelCase_byAnyChance, note, that
                // double`__` are kept as single `_`
                $feature['context'] = explode('-', $feature['context']);
                foreach ($feature['context'] as &$v) {
                    if ($v[0]==='_' && $v[(strlen($v)-1)]==='_') {
                        $v = explode('  ', str_replace('_', ' ', $v));
                        foreach ($v as &$_v) {
                            $_v = str_replace(' ', '', lcfirst(ucwords(trim($_v))));
                        }
                        $v = implode('_', $v);
                    }
                }

                $feature['context'] = implode('.', $feature['context']);
                print_r($feature);

                // Tag to look for to close
                $feature['tag'] = array_shift(explode(' ', trim($fragment, " \n\r\t</>")));

                // Pretty spaces
                $feature['spaces'] = $i===0 ? '' : preg_replace('/[^ ]/', '', $html[($i-1)]);

                if ($lookup === 'repeat') {
                    $hasSection = explode('.', $feature['context']);
                    $hasSection[(count($hasSection)-1)] = 'has'.ucfirst($hasSection[(count($hasSection)-1)]);
                    $feature['hasSection'] = implode('.', $hasSection);
                }

                // Write buffer with opening tag
                if (!isset($feature['hasSection'])) {
                    $out[] = "{{#{$feature['context']}}}\n{$feature['spaces']}";
                } elseif(!! DependencyContainer::get('Macaw::useHasSections', true)) {
                    $out[] = "{{#{$feature['hasSection']}}}\n{$feature['spaces']}";
                }

                if (in_array($tag, $void_tags)) {
                    exit('@TODO: Self-closing tags.');
                }

                $open[] = $feature;
                // echo "\n".'Open: ';print_r($feature);

                // Write buffer
                $out[] = $this->transcriptToMustache($fragment, $feature);

                if (isset($feature['hasSection'])) {
                    $out[] = "\n{$feature['spaces']}  {{#{$feature['context']}}}";
                }
            } elseif(count($open)) {
                if (strstr($fragment, '</')) {
                    // Level up
                    $feature = array_pop($open);
                    // echo "\n".'Close: ';print_r($feature);

                    // Found the closing tag match
                    if (trim($fragment, '</>') !== $feature['tag']) {
                        throw new HTTPException(500, 'Closing tags mismatch near tag `'.$fragment.'` vs '.$feature['tag']);
                    }

                    if (isset($feature['context'])) {
                        if (isset($feature['hasSection'])) {
                            $out[] = "  {{/{$feature['context']}}}\n{$feature['spaces']}";
                        }

                        $out[] = $this->transcriptToMustache($fragment, $feature);

                        // Write buffer with closing tag
                        if (!isset($feature['hasSection'])) {
                            $out[] = "\n{$feature['spaces']}{{/{$feature['context']}}}";
                        } elseif(!! DependencyContainer::get('Macaw::useHasSections', true)) {
                            $out[] = "\n{$feature['spaces']}{{/{$feature['hasSection']}}}";
                        }
                    } else {
                        $out[] = $this->transcriptToMustache($fragment, $feature);
                    }
                } elseif (strstr($fragment, '<')) {
                    // New child tag creates empty feature
                    $tag = array_shift(explode(' ', trim($fragment, " \n\r\t</>")));

                    // Skip autoclosing tags
                    if (! in_array($tag, $void_tags)) {
                        $feature = array('tag' => $tag);
                        $open[] = $feature;

                        // echo "\n".'Open: ';print_r($feature);
                    }

                    // Write buffer
                    $out[] = $this->transcriptToMustache($fragment, $feature);
                } else {
                    // Write buffer
                    $out[] = $this->transcriptToMustache($fragment, $feature);
                }
            } else {
                // Write buffer
                $out[] = $this->transcriptToMustache($fragment, $feature);
            }
        }

        return $this->transcriptTranlations(implode('', $out));
    }
}
