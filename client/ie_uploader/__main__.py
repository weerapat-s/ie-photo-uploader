import multiprocessing

from .app import main

if __name__ == "__main__":
    multiprocessing.freeze_support()   # required for ProcessPoolExecutor in PyInstaller builds
    main()
