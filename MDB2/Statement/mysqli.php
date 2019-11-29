<?php
/**
 * MDB2 MySQLi statement driver
 *
 * @package MDB2
 * @category Database
 * @author  Lukas Smith <smith@pooteeweet.org>
 */
class MDB2_Statement_mysqli extends MDB2_Statement_Common
{
    // {{{ _execute()

    /**
     * Execute a prepared query statement helper method.
     *
     * @param mixed $result_class string which specifies which result class to use
     * @param mixed $result_wrap_class string which specifies which class to wrap results in
     *
     * @return mixed MDB2_Result or integer (affected rows) on success,
     *               a MDB2 error on failure
     * @access private
     */
    function executeInternal($result_class = true, $result_wrap_class = true)
    {
        if (null === $this->statement) {
            $result = parent::executeInternal($result_class, $result_wrap_class);
            return $result;
        }
        $this->db->last_query = $this->query;
        $this->db->debug($this->query, 'execute', array('is_manip' => $this->is_manip, 'when' => 'pre', 'parameters' => $this->values));
        if ($this->db->getOption('disable_query')) {
            $result = $this->is_manip ? 0 : null;
            return $result;
        }

        $connection = $this->db->getConnection();
        if (MDB2::isError($connection)) {
            return $connection;
        }

        if (!is_object($this->statement)) {
            $query = 'EXECUTE '.$this->statement;
        }
        if (!empty($this->positions)) {
            $paramReferences = array();
            $parameters = array(0 => $this->statement, 1 => '');
            $lobs = array();
            $i = 0;
            foreach ($this->positions as $parameter) {
                if (!array_key_exists($parameter, $this->values)) {
                    return $this->db->raiseError(MDB2_ERROR_NOT_FOUND, null, null,
                        'Unable to bind to missing placeholder: '.$parameter, __FUNCTION__);
                }
                $value = $this->values[$parameter];
                $type = array_key_exists($parameter, $this->types) ? $this->types[$parameter] : null;
                if (!is_object($this->statement)) {
                    if (is_resource($value) || $type == 'clob' || $type == 'blob' && $this->db->options['lob_allow_url_include']) {
                        if (!is_resource($value) && preg_match('/^(\w+:\/\/)(.*)$/', $value, $match)) {
                            if ($match[1] == 'file://') {
                                $value = $match[2];
                            }
                            $value = @fopen($value, 'r');
                            $close = true;
                        }
                        if (is_resource($value)) {
                            $data = '';
                            while (!@feof($value)) {
                                $data.= @fread($value, $this->db->options['lob_buffer_length']);
                            }
                            if ($close) {
                                @fclose($value);
                            }
                            $value = $data;
                        }
                    }
                    $quoted = $this->db->quote($value, $type);
                    if (MDB2::isError($quoted)) {
                        return $quoted;
                    }
                    $param_query = 'SET @'.$parameter.' = '.$quoted;
                    $result = $this->db->doQuery($param_query, true, $connection);
                    if (MDB2::isError($result)) {
                        return $result;
                    }
                } else {
                    if (is_resource($value) || $type == 'clob' || $type == 'blob') {
                        $paramReferences[$i] = null;
                        // mysqli_stmt_bind_param() requires parameters to be passed by reference
                        $parameters[] =& $paramReferences[$i];
                        $parameters[1].= 'b';
                        $lobs[$i] = $parameter;
                    } else {
                        $paramReferences[$i] = $this->db->quote($value, $type, false);
                        if (MDB2::isError($paramReferences[$i])) {
                            return $paramReferences[$i];
                        }
                        // mysqli_stmt_bind_param() requires parameters to be passed by reference
                        $parameters[] =& $paramReferences[$i];
                        $parameters[1].= $this->db->datatype->mapPrepareDatatype($type);
                    }
                    ++$i;
                }
            }

            if (!is_object($this->statement)) {
                $query.= ' USING @'.implode(', @', array_values($this->positions));
            } else {
                $result = call_user_func_array('mysqli_stmt_bind_param', $parameters);
                if (false === $result) {
                    $err = $this->db->raiseError(null, null, null,
                        'Unable to bind parameters', __FUNCTION__);
                    return $err;
                }

                foreach ($lobs as $i => $parameter) {
                    $value = $this->values[$parameter];
                    $close = false;
                    if (!is_resource($value)) {
                        $close = true;
                        if (preg_match('/^(\w+:\/\/)(.*)$/', $value, $match)) {
                            if ($match[1] == 'file://') {
                                $value = $match[2];
                            }
                            $value = @fopen($value, 'r');
                        } else {
                            $fp = @tmpfile();
                            @fwrite($fp, $value);
                            @rewind($fp);
                            $value = $fp;
                        }
                    }
                    while (!@feof($value)) {
                        $data = @fread($value, $this->db->options['lob_buffer_length']);
                        @mysqli_stmt_send_long_data($this->statement, $i, $data);
                    }
                    if ($close) {
                        @fclose($value);
                    }
                }
            }
        }

        if (!is_object($this->statement)) {
            $result = $this->db->doQuery($query, $this->is_manip, $connection);
            if (MDB2::isError($result)) {
                return $result;
            }

            if ($this->is_manip) {
                $affected_rows = $this->db->affectedRows($connection, $result);
                return $affected_rows;
            }

            $result = $this->db->wrapResult($result, $this->result_types,
                $result_class, $result_wrap_class, $this->limit, $this->offset);
        } else {
            if (!mysqli_stmt_execute($this->statement)) {
                $err = $this->db->raiseError(null, null, null,
                    'Unable to execute statement', __FUNCTION__);
                return $err;
            }

            if ($this->is_manip) {
                $affected_rows = @mysqli_stmt_affected_rows($this->statement);
                return $affected_rows;
            }

            if ($this->db->options['result_buffering']) {
                @mysqli_stmt_store_result($this->statement);
            }

            $result = $this->db->wrapResult($this->statement, $this->result_types,
                $result_class, $result_wrap_class, $this->limit, $this->offset);
        }

        $this->db->debug($this->query, 'execute', array('is_manip' => $this->is_manip, 'when' => 'post', 'result' => $result));
        return $result;
    }

    // }}}
    // {{{ free()

    /**
     * Release resources allocated for the specified prepared query.
     *
     * @return mixed MDB2_OK on success, a MDB2 error on failure
     * @access public
     */
    function free()
    {
        if (null === $this->positions) {
            return $this->db->raiseError(MDB2_ERROR, null, null,
                'Prepared statement has already been freed', __FUNCTION__);
        }
        $result = MDB2_OK;

        if (is_object($this->statement)) {
            if (!@mysqli_stmt_close($this->statement)) {
                $result = $this->db->raiseError(null, null, null,
                    'Could not free statement', __FUNCTION__);
            }
        } elseif (null !== $this->statement) {
            $connection = $this->db->getConnection();
            if (MDB2::isError($connection)) {
                return $connection;
            }

            $query = 'DEALLOCATE PREPARE '.$this->statement;
            $result = $this->db->doQuery($query, true, $connection);
        }

        parent::free();
        return $result;
   }
}

