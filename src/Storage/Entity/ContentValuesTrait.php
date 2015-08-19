<?php
namespace Bolt\Storage\Entity;

/**
 * Trait class for ContentType record values.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
trait ContentValuesTrait
{
    /**
     * Return a content objects values.
     *
     * @param boolean $json     Set to TRUE to return JSON encoded values for arrays
     * @param boolean $stripped Set to true to strip all of the base fields
     *
     * @return array
     */
    public function getValues($json = false, $stripped = false)
    {
        // Prevent 'slug may not be NULL'
        if (!isset($this->values['slug'])) {
            $this->values['slug'] = '';
        }

        // Return raw values
        if ($json === false) {
            return $this->values;
        }

        $contenttype = $this->contenttype;
        if (!$stripped) {
            $newvalue = $this->values;
        } else {
            $newvalue = [];
        }

        // add the fields for this contenttype,
        if (is_array($contenttype)) {
            foreach ($contenttype['fields'] as $field => $property) {
                switch ($property['type']) {

                    // Set the slug, while we're at it
                    case 'slug':
                        if (!empty($property['uses']) && empty($this->values[$field])) {
                            $uses = '';
                            foreach ($property['uses'] as $usesField) {
                                $uses .= $this->values[$usesField] . ' ';
                            }
                            $newvalue[$field] = $this->app['slugify']->slugify($uses);
                        } elseif (!empty($this->values[$field])) {
                            $newvalue[$field] = $this->app['slugify']->slugify($this->values[$field]);
                        } elseif (empty($this->values[$field]) && $this->values['id']) {
                            $newvalue[$field] = $this->values['id'];
                        }
                        break;

                    case 'video':
                        foreach (['html', 'responsive'] as $subkey) {
                            if (!empty($this->values[$field][$subkey])) {
                                $this->values[$field][$subkey] = (string) $this->values[$field][$subkey];
                            }
                        }
                        if (!empty($this->values[$field]['url'])) {
                            $newvalue[$field] = json_encode($this->values[$field]);
                        } else {
                            $newvalue[$field] = '';
                        }
                        break;

                    case 'geolocation':
                        if (!empty($this->values[$field]['latitude']) && !empty($this->values[$field]['longitude'])) {
                            $newvalue[$field] = json_encode($this->values[$field]);
                        } else {
                            $newvalue[$field] = '';
                        }
                        break;

                    case 'image':
                        if (!empty($this->values[$field]['file'])) {
                            $newvalue[$field] = json_encode($this->values[$field]);
                        } else {
                            $newvalue[$field] = '';
                        }
                        break;

                    case 'imagelist':
                    case 'filelist':
                        if (is_array($this->values[$field])) {
                            $newvalue[$field] = json_encode($this->values[$field]);
                        } elseif (!empty($this->values[$field]) && strlen($this->values[$field]) < 3) {
                            // Don't store '[]'
                            $newvalue[$field] = '';
                        }
                        break;

                    case 'integer':
                        $newvalue[$field] = round($this->values[$field]);
                        break;

                    case 'select':
                        if (is_array($this->values[$field])) {
                            $newvalue[$field] = json_encode($this->values[$field]);
                        }
                        break;

                    case 'html':
                        // Remove &nbsp; characters from CKEditor, unless configured to leave them in.
                        if (!$this->app['config']->get('general/wysiwyg/ck/allowNbsp')) {
                            $newvalue[$field] = str_replace('&nbsp;', ' ', $this->values[$field]);
                        }
                        break;
                    default:
                        $newvalue[$field] = $this->values[$field];
                        break;
                }
            }
        }

        if (!$stripped) {
            if (!empty($this['templatefields'])) {
                $newvalue['templatefields'] = json_encode($this->values['templatefields']->getValues(true, true));
            } else {
                $newvalue['templatefields'] = '';
            }
        }

        return $newvalue;
    }

    /**
     * Set a Contenttype record's individual value.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function setValue($key, $value)
    {
        // Don't set templateFields if not a real contenttype
        if (($key === 'templatefields') && (!$this->isRootType)) {
            return;
        }

        // Check if the value need to be unserialized.
        if (is_string($value) && substr($value, 0, 2) === "a:") {
            try {
                $unserdata = Lib::smartUnserialize($value);
            } catch (\Exception $e) {
                $unserdata = false;
            }

            if ($unserdata !== false) {
                $value = $unserdata;
            }
        }

        if ($key == 'id') {
            $this->id = $value;
        }

        // Set the user in the object.
        if ($key === 'ownerid' && !empty($value)) {
            $this->user = $this->app['users']->getUser($value);
        }

        // Only set values if they have are actually a field.
        $allowedcolumns = self::getBaseColumns();
        $allowedcolumns[] = 'taxonomy';
        if (!isset($this->contenttype['fields'][$key]) && !in_array($key, $allowedcolumns)) {
            return;
        }

        if (in_array($key, ['datecreated', 'datechanged', 'datepublish', 'datedepublish'])) {
            if (!preg_match("/(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/", $value)) {
                // @todo Try better date-parsing, instead of just setting it to
                // 'now' (or 'the past' for datedepublish)
                if ($key == 'datedepublish') {
                    $value = null;
                } else {
                    $value = date('Y-m-d H:i:s');
                }
            }
        }

        if ($key === 'templatefields') {
            if ((is_string($value)) || (is_array($value))) {
                if (is_string($value)) {
                    try {
                        $unserdata = Lib::smartUnserialize($value);
                    } catch (\Exception $e) {
                        $unserdata = false;
                    }
                } else {
                    $unserdata = $value;
                }

                if (is_array($unserdata)) {
                    $templateContent = new Content($this->app, $this->getTemplateFieldsContentType(), [], false);
                    $value = $templateContent;
                    $this->populateTemplateFieldsContenttype($value);
                    $templateContent->setValues($unserdata);
                } else {
                    $value = null;
                }
            }
        }

        if (!isset($this->values['datechanged']) ||
            !preg_match("/(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/", $this->values['datechanged'])) {
                $this->values['datechanged'] = date("Y-m-d H:i:s");
            }

            $this->values[$key] = $value;
    }

    /**
     * Set a Contenttype record's values.
     *
     * @param array $values
     */
    public function setValues(array $values)
    {
        // Since Bolt 1.4, we use 'ownerid' instead of 'username' in the DB tables. If we get an array that has an
        // empty 'ownerid', attempt to set it from the 'username'. In $this->setValue the user will be set, regardless
        // of ownerid is an 'id' or a 'username'.
        if (empty($values['ownerid']) && !empty($values['username'])) {
            $values['ownerid'] = $values['username'];
            unset($values['username']);
        }

        foreach ($values as $key => $value) {
            if ($key !== 'templatefields') {
                $this->setValue($key, $value);
            }
        }

        // If default status is set in contentttype.
        if (empty($this->values['status']) && isset($this->contenttype['default_status'])) {
            $this->values['status'] = $this->contenttype['default_status'];
        }

        $serializedFieldTypes = [
            'geolocation',
            'imagelist',
            'image',
            'file',
            'filelist',
            'video',
            'select',
            'templateselect',
            'checkbox'
        ];
        // Check if the values need to be unserialized, and pre-processed.
        foreach ($this->values as $key => $value) {
            if ((in_array($this->fieldtype($key), $serializedFieldTypes)) || ($key == 'templatefields')) {
                if (!empty($value) && is_string($value) && (substr($value, 0, 2) == "a:" || $value[0] === '[' || $value[0] === '{')) {
                    try {
                        $unserdata = Lib::smartUnserialize($value);
                    } catch (\Exception $e) {
                        $unserdata = false;
                    }

                    if ($unserdata !== false) {
                        $this->values[$key] = $unserdata;
                    }
                }
            }

            if ($this->fieldtype($key) == "video" && is_array($this->values[$key]) && !empty($this->values[$key]['url'])) {
                $video = $this->values[$key];

                // update the HTML, according to given width and height
                if (!empty($video['width']) && !empty($video['height'])) {
                    $video['html'] = preg_replace("/width=(['\"])([0-9]+)(['\"])/i", 'width=${1}' . $video['width'] . '${3}', $video['html']);
                    $video['html'] = preg_replace("/height=(['\"])([0-9]+)(['\"])/i", 'height=${1}' . $video['height'] . '${3}', $video['html']);
                }

                $responsiveclass = "responsive-video";

                // See if it's widescreen or not.
                if (!empty($video['height']) && (($video['width'] / $video['height']) > 1.76)) {
                    $responsiveclass .= " widescreen";
                }

                if (strpos($video['url'], "vimeo") !== false) {
                    $responsiveclass .= " vimeo";
                }

                $video['responsive'] = sprintf('<div class="%s">%s</div>', $responsiveclass, $video['html']);

                // Mark them up as Twig_Markup.
                $video['html'] = new \Twig_Markup($video['html'], 'UTF-8');
                $video['responsive'] = new \Twig_Markup($video['responsive'], 'UTF-8');

                $this->values[$key] = $video;
            }

            if ($this->fieldtype($key) == "date" || $this->fieldtype($key) == "datetime") {
                if ($this->values[$key] === "") {
                    $this->values[$key] = null;
                }
            }
        }

        // Template fields need to be done last
        // As the template has to have been selected
        if ($this->isRootType) {
            if (empty($values['templatefields'])) {
                $this->setValue('templatefields', []);
            } else {
                $this->setValue('templatefields', $values['templatefields']);
            }
        }
    }
}
