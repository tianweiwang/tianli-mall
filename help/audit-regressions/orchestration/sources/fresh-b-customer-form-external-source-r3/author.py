"""Current mutable B Source binder; complete former2fa author retained in rebind-before-53e."""
from pathlib import Path
import runpy
if __name__ == '__main__':
    runpy.run_path(str(Path(__file__).parent/'rebind_current53e.py'),run_name='__main__')
