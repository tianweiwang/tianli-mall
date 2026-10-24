"""Static actual-module closure only. Does not evaluate a source_authority callback."""
import ast,builtins,symtable

def actual_namespace_static_qualification(raw,label):
    text=raw.decode('utf8');tree=ast.parse(text);bound=set();imports=[]
    for node in tree.body:
        if isinstance(node,ast.Import):
            for alias in node.names:
                module=alias.name.split('.')[0]
                assert module in __import__('sys').stdlib_module_names
                bound.add(alias.asname or module);imports.append(alias.name)
        elif isinstance(node,ast.ImportFrom):
            assert node.module.split('.')[0] in __import__('sys').stdlib_module_names
            for alias in node.names:
                assert alias.name!='*';bound.add(alias.asname or alias.name)
            imports.append(node.module)
        elif isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
            try:ast.literal_eval(node.value)
            except (TypeError,ValueError):continue
            bound.add(node.targets[0].id)
        elif isinstance(node,ast.FunctionDef) and node.name=='source_authority':
            bound.add(node.name)
    root=symtable.symtable(text,'<actual-'+label+'-static>','exec');required=set()
    def descend(scope):
        required.update(s.get_name() for s in scope.get_symbols() if s.is_global() and s.is_referenced())
        for child in scope.get_children():descend(child)
    descend(next(c for c in root.get_children() if c.get_name()=='source_authority'))
    missing=sorted(required-bound-set(dir(builtins)));assert missing==[],(label,missing)
    return {'module':label,'qualification_namespace':'ONLY_ACTUAL_DECLARED_MODULE_IMPORTS_AND_LITERAL_ASSIGNMENTS_STATIC',
            'manual_dependency_injection':False,'recursive_global_dependencies':sorted(required),
            'unbound_globals':missing,'callback_executed':False,'business_executed':False}
