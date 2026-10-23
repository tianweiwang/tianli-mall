# Preserved own checker correction

The first static check stopped at the CU7 join after all complete31 before/candidate files were written. The fixed normal Python sources use tuples; the interface JSON necessarily uses an array. Their ordered seven complete row values were identical. The checker had compared a Python tuple directly with the JSON list. The original checker, traceback and complete62 before/candidate file pins are preserved in `construction-errors/attempt-01-*`.

The corrected check asserts the original types separately (tuple/tuple/list), then compares the Python tuples with `tuple(JSON_array)` without modifying any candidate or assertion. Re-entry accepts an existing own file only when its complete bytes already equal the intended bytes; it never overwrites a changed file. No prepared Source program was run.
