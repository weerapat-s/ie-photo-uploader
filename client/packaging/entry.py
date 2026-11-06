# PyInstaller entry point. ie_uploader/__main__.py uses a relative import, which
# fails when run as the top-level script of a frozen app.
import multiprocessing

from ie_uploader.app import main

if __name__ == "__main__":
    multiprocessing.freeze_support()   # required for ProcessPoolExecutor in frozen builds
    main()
