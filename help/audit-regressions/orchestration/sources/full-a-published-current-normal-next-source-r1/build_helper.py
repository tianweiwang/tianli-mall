from pathlib import Path
B=Path(__file__).parent.parent
OUT=Path(__file__).parent
s=(B/'full-a-raw-env-normal-next-source-r1/source_reader.py').read_bytes()
for before,after in [
 (b"OLD=B/'full-a-initializer-customer-form-mount-next-source-r1'",b"OLD=B/'full-a-raw-env-normal-next-source-r1'"),
 (b"HEAD='53e6d521b387b19a4cf370e0e381670b762d325e'",b"HEAD='584532ba22b1824164aa11c06b163715be8c224c'"),
 (b"PARENT='2fa872e6fda7a8bf46c55f88dd946b972ec79102'",b"PARENT='dc72de6cc1c91d378c7f884421685be726c81550'"),
]:
 assert s.count(before)==1
 s=s.replace(before,after)
p=OUT/'source_reader.py'
if p.exists():assert p.read_bytes()==s
else:p.write_bytes(s)
print('NEW_SOURCE_READER_CREATED_ONLY')
