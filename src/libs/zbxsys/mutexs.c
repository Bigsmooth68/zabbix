/* 
** ZABBIX
** Copyright (C) 2000-2005 SIA Zabbix
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
**/

#include "common.h"
#include "mutexs.h"

#if !defined(_WINDOWS)

#	if !defined(semun)
		union semun
		{
			int val;			/* <= value for SETVAL */
			struct semid_ds *buf;		/* <= buffer for IPC_STAT & IPC_SET */
			unsigned short int *array;	/* <= array for GETALL & SETALL */
			struct seminfo *__buf;		/* <= buffer for IPC_INFO */
		};
#	endif /* semun */

#	include "cfg.h"
#	include "threads.h"

	static int	ZBX_SEM_LIST_ID = -1;

#endif /* not _WINDOWS */

#include "log.h"

/******************************************************************************
 *                                                                            *
 * Function: zbx_mutex_create                                                 *
 *                                                                            *
 * Purpose: Create the mutex                                                  *
 *                                                                            *
 * Parameters:  mutex - handle of mutex                                       *
 *                                                                            *
 * Return value: If the function succeeds, the return ZBX_MUTEX_OK,           *
 *               ZBX_MUTEX_ERROR on an error                                  *
 *                                                                            *
 * Author: Eugene Grigorjev                                                   *
 *                                                                            *
 * Comments:                                                                  *
 *                                                                            *
 ******************************************************************************/
int zbx_mutex_create(ZBX_MUTEX *mutex, ZBX_MUTEX_NAME name)
{
#if defined(_WINDOWS)	

	if(NULL == ((*mutex) = CreateMutex(NULL, FALSE, name)))
	{
		zbx_error("Error on mutex creating. [%s]", strerror_from_system(GetLastError()));
		return ZBX_MUTEX_ERROR;
	}

	/* NOTE: if(ERROR_ALREADY_EXISTS == GetLastError()) info("Successfully opened existed mutex!"); */

#else /* not _WINDOWS */
	int	i;
	key_t	sem_key;
	union semun semopts;
	struct semid_ds seminfo;

	if( -1 == (sem_key = ftok(CONFIG_FILE, (int)'z') ))
	{
		zbx_error("Can not create IPC key for path '%s', try to create for path '.' [%s]", CONFIG_FILE, strerror(errno));
		if( -1 == (sem_key = ftok(".", (int)'z') ))
		{
			zbx_error("Can not create IPC key for path '.' [%s]", strerror(errno));
			return ZBX_MUTEX_ERROR;
		}
	}			

	if ( -1 != (ZBX_SEM_LIST_ID = semget(sem_key, ZBX_MUTEX_COUNT, IPC_CREAT | IPC_EXCL | 0666 /* 0022 */)) )
	{
		/* set default semaphore value */
		semopts.val = 1;
		
		for ( i = 0; i < ZBX_MUTEX_COUNT; i++ )
		{
			if(-1 == semctl(ZBX_SEM_LIST_ID, i, SETVAL, semopts))
			{
				zbx_error("Semaphore [%i] error in semctl(SETVAL)", name);
				return ZBX_MUTEX_ERROR;
			}

			zbx_mutex_lock(&i);	/* call semop to update sem_otime */
			zbx_mutex_unlock(&i);	/* release semaphore */
		}
	}
	else if(errno == EEXIST)
	{
		ZBX_SEM_LIST_ID = semget(sem_key, ZBX_MUTEX_COUNT, 0666 /* 0022 */);
		semopts.buf = &seminfo;
		
		/* wait for initialization */
		for ( i = 0; i < ZBX_MUTEX_MAX_TRIES; i++)
		{
			if( -1 == semctl(ZBX_SEM_LIST_ID, 0, IPC_STAT, semopts))
			{
				zbx_error("Semaphore [%i] error in semctl(IPC_STAT)", name);
				break;
			}
			if(semopts.buf->sem_otime !=0 ) goto lbl_return;
			zbx_sleep(1);
		}
		
		zbx_error("Semaphore [%i] not initialized", name);
		return ZBX_MUTEX_ERROR;
	}
	else
	{
		zbx_error("Can not create Semaphore [%s]", strerror(errno));
		return ZBX_MUTEX_ERROR;
	}
	
lbl_return:

	*mutex = name;
	
#endif /* _WINDOWS */

	return ZBX_MUTEX_OK;
}

/******************************************************************************
 *                                                                            *
 * Function: zbx_mutex_lock                                                   *
 *                                                                            *
 * Purpose: Waits until the mutex is in the signaled state                    *
 *                                                                            *
 * Parameters: mutex - handle of mutex                                        *
 *                                                                            *
 * Return value: If the function succeeds, the return 1, 0 on an error        *
 *                                                                            *
 * Author: Eugene Grigorjev                                                   *
 *                                                                            *
 * Comments:                                                                  *
 *                                                                            *
 ******************************************************************************/

int zbx_mutex_lock(ZBX_MUTEX *mutex)
{
#if defined(_WINDOWS)	

	if(!*mutex) return ZBX_MUTEX_OK;
	
	if(WaitForSingleObject(*mutex, INFINITE) != WAIT_OBJECT_0)
	{
		zbx_error("Error on mutex locking. [%s]", strerror_from_system(GetLastError()));
		return ZBX_MUTEX_ERROR;
	}

#else /* not _WINDOWS */

	struct sembuf sem_lock = { *mutex, -1, 0 };

	if(!*mutex) return ZBX_MUTEX_OK;
	
	if (-1 == (semop(ZBX_SEM_LIST_ID, &sem_lock, 1)))
	{
		zbx_error("Lock failed [%s]", strerror(errno));
		return ZBX_MUTEX_ERROR;
	}
	
#endif /* _WINDOWS */

	return ZBX_MUTEX_OK;
}

/******************************************************************************
 *                                                                            *
 * Function: zbx_mutex_unlock                                                 *
 *                                                                            *
 * Purpose: Unlock the mutex                                                  *
 *                                                                            *
 * Parameters: mutex - handle of mutex                                        *
 *                                                                            *
 * Return value: If the function succeeds, the return 1, 0 on an error        *
 *                                                                            *
 * Author: Eugene Grigorjev                                                   *
 *                                                                            *
 * Comments:                                                                  *
 *                                                                            *
 ******************************************************************************/

int zbx_mutex_unlock(ZBX_MUTEX *mutex)
{
#if defined(_WINDOWS)	

	if(!*mutex) return ZBX_MUTEX_OK;

	if(ReleaseMutex(*mutex) == 0)
	{
		zbx_error("Error on mutex UNlocking. [%s]", strerror_from_system(GetLastError()));
		return ZBX_MUTEX_ERROR;
	}

#else /* not _WINDOWS */

	struct sembuf sem_unlock = { *mutex, 1, 0};

	if(!*mutex) return ZBX_MUTEX_OK;

	if ((semop(ZBX_SEM_LIST_ID, &sem_unlock, 1)) == -1)
	{
		zbx_error("Unlock failed [%s]", strerror(errno));
		return ZBX_MUTEX_ERROR;
	}
	
#endif /* _WINDOWS */

	return ZBX_MUTEX_OK;
}

/******************************************************************************
 *                                                                            *
 * Function: zbx_mutex_destroy                                                *
 *                                                                            *
 * Purpose: Destroy the mutex                                                 *
 *                                                                            *
 * Parameters: mutex - handle of mutex                                        *
 *                                                                            *
 * Return value: If the function succeeds, the return 1, 0 on an error        *
 *                                                                            *
 * Author: Eugene Grigorjev                                                   *
 *                                                                            *
 * Comments:                                                                  *
 *                                                                            *
 ******************************************************************************/

int zbx_mutex_destroy(ZBX_MUTEX *mutex)
{
	
#if defined(_WINDOWS)	

	if(!*mutex) return ZBX_MUTEX_OK;

	if(CloseHandle(*mutex) == 0)
	{
		zbx_error("Error on mutex destroying. [%s]", strerror_from_system(GetLastError()));
		return ZBX_MUTEX_ERROR;
	}

#else /* not _WINDOWS */
	
	if(!*mutex) return ZBX_MUTEX_OK;

	semctl(ZBX_SEM_LIST_ID, 0, IPC_RMID, 0);

#endif /* _WINDOWS */
	
	*mutex = (ZBX_MUTEX)NULL;

	return ZBX_MUTEX_OK;
}


#if defined(HAVE_SQLITE3) && !defined(_WINDOWS)

/*
   +----------------------------------------------------------------------+
   | PHP Version 5                                                        |
   +----------------------------------------------------------------------+
   | Copyright (c) 1997-2006 The PHP Group                                |
   +----------------------------------------------------------------------+
   | This source file is subject to version 3.01 of the PHP license,      |
   | that is bundled with this package in the file LICENSE, and is        |
   | available through the world-wide-web at the following url:           |
   | http://www.php.net/license/3_01.txt                                  |
   | If you did not receive a copy of the PHP license and are unable to   |
   | obtain it through the world-wide-web, please send a note to          |
   | license@php.net so we can mail you a copy immediately.               |
   +----------------------------------------------------------------------+
   | Authors: Tom May <tom@go2net.com>                                    |
   |          Gavin Sherry <gavin@linuxworld.com.au>                      |
   +----------------------------------------------------------------------+
 */
 
/* Semaphore functions using System V semaphores.  Each semaphore
 * actually consists of three semaphores allocated as a unit under the
 * same key.  Semaphore 0 (SYSVSEM_SEM) is the actual semaphore, it is
 * initialized to max_acquire and decremented as processes acquire it.
 * The value of semaphore 1 (SYSVSEM_USAGE) is a count of the number
 * of processes using the semaphore.  After calling semget(), if a
 * process finds that the usage count is 1, it will set the value of
 * SYSVSEM_SEM to max_acquire.  This allows max_acquire to be set and
 * track the PHP code without having a global init routine or external
 * semaphore init code.  Except see the bug regarding a race condition
 * php_sysvsem_get().  Semaphore 2 (SYSVSEM_SETVAL) serializes the
 * calls to GETVAL SYSVSEM_USAGE and SETVAL SYSVSEM_SEM.  It can be
 * acquired only when it is zero.
 */

#define SYSVSEM_SEM	0
#define SYSVSEM_USAGE	1
#define SYSVSEM_SETVAL	2

int php_sem_get(ZBX_MUTEX* sem_ptr, char* path_name)
{
	int	
		key,
		max_acquire = 1,
		count;

	key_t	sem_key;

	ZBX_MUTEX semid;
	
	struct sembuf	sop[3];
	
	assert(sem_ptr);
	assert(path_name);

	*sem_ptr = 0;

	if( -1 == (sem_key = ftok(path_name, (int)'z') ))
	{
		zbx_error("Can not create IPC key for path '%s', try to create for path '.' [%s]", path_name, strerror(errno));
		if( -1 == (sem_key = ftok(".", (int)'z') ))
		{
			zbx_error("Can not create IPC key for path '.' [%s]", strerror(errno));
			return ZBX_MUTEX_ERROR;
		}
	}			

	/* Get/create the semaphore.  Note that we rely on the semaphores
	 * being zeroed when they are created.  Despite the fact that
	 * the(?)  Linux semget() man page says they are not initialized,
	 * the kernel versions 2.0.x and 2.1.z do in fact zero them.
	 */

	semid = semget(sem_key, 3, 0666 | IPC_CREAT);
	if (semid == -1) {
		zbx_error("failed for key 0x%lx: %s", key, strerror(errno));
		return ZBX_MUTEX_ERROR;
	}

	/* Find out how many processes are using this semaphore.  Note
	 * that on Linux (at least) there is a race condition here because
	 * semaphore undo on process exit is not atomic, so we could
	 * acquire SYSVSEM_SETVAL before a crashed process has decremented
	 * SYSVSEM_USAGE in which case count will be greater than it
	 * should be and we won't set max_acquire.  Fortunately this
	 * doesn't actually matter in practice.
	 */

	/* Wait for sem 1 to be zero . . . */

	sop[0].sem_num = SYSVSEM_SETVAL;
	sop[0].sem_op  = 0;
	sop[0].sem_flg = 0;

	/* . . . and increment it so it becomes non-zero . . . */

	sop[1].sem_num = SYSVSEM_SETVAL;
	sop[1].sem_op  = 1;
	sop[1].sem_flg = SEM_UNDO;

	/* . . . and increment the usage count. */

	sop[2].sem_num = SYSVSEM_USAGE;
	sop[2].sem_op  = 1;
	sop[2].sem_flg = SEM_UNDO;
	while (semop(semid, sop, 3) == -1) {
		if (errno != EINTR) {
			zbx_error("failed acquiring SYSVSEM_SETVAL for key 0x%lx: %s", key, strerror(errno));
			break;
		}
	}

	/* Get the usage count. */
	count = semctl(semid, SYSVSEM_USAGE, GETVAL, NULL);
	if (count == -1) {
		zbx_error("failed for key 0x%lx: %s", key, strerror(errno));
	}

	/* If we are the only user, then take this opportunity to set the max. */

	if (count == 1) {
		/* This is correct for Linux which has union semun. */
		union semun semarg;
		semarg.val = max_acquire;
		if (semctl(semid, SYSVSEM_SEM, SETVAL, semarg) == -1) {
			zbx_error("failed for key 0x%lx: %s", key, strerror(errno));
		}
	}

	/* Set semaphore 1 back to zero. */

	sop[0].sem_num = SYSVSEM_SETVAL;
	sop[0].sem_op  = -1;
	sop[0].sem_flg = SEM_UNDO;
	while (semop(semid, sop, 1) == -1) {
		if (errno != EINTR) {
			zbx_error("failed releasing SYSVSEM_SETVAL for key 0x%lx: %s", key, strerror(errno));
			break;
		}
	}

	*sem_ptr = semid;

	return ZBX_MUTEX_OK;
}

static int php_sysvsem_semop(ZBX_MUTEX* sem_ptr, int acquire)
{
	struct sembuf sop;

	assert(sem_ptr);

	if(!*sem_ptr)	return ZBX_MUTEX_OK;

	sop.sem_num = SYSVSEM_SEM;
	sop.sem_op  = acquire ? -1 : 1;
	sop.sem_flg = SEM_UNDO;

	while (semop(*sem_ptr, &sop, 1) == -1) {
		if (errno != EINTR) {
			zbx_error("failed to %s semaphore (id 0x%d): %s", acquire ? "acquire" : "release", *sem_ptr, strerror(errno));
			return ZBX_MUTEX_ERROR;
		}
	}

	return ZBX_MUTEX_OK;
}

int php_sem_acquire(ZBX_MUTEX* sem_ptr)
{
	return php_sysvsem_semop(sem_ptr, 1);
}

int php_sem_release(ZBX_MUTEX* sem_ptr)
{
	return php_sysvsem_semop(sem_ptr, 0);
}

int php_sem_remove(ZBX_MUTEX* sem_ptr)
{
	union semun		un;
	struct semid_ds	buf;
	struct sembuf	sop;


	assert(sem_ptr);

	if(!*sem_ptr)	return ZBX_MUTEX_OK;

	/* Decrement the usage count. */

	sop.sem_num = SYSVSEM_USAGE;
	sop.sem_op  = -1;
	sop.sem_flg = SEM_UNDO;

	if (semop(*sem_ptr, &sop, 1) == -1) {
		zbx_error("failed in php_sem_remove for (id 0x%x): %s", *sem_ptr, strerror(errno));
		return ZBX_MUTEX_ERROR;
	}
	
	un.buf = &buf;
	if (semctl(*sem_ptr, 0, IPC_STAT, un) < 0) {
		zbx_error("SysV semaphore (id 0x%x) does not (any longer) exist", *sem_ptr);
		return ZBX_MUTEX_ERROR;
	}

	if (semctl(*sem_ptr, 0, IPC_RMID, un) < 0) {
		zbx_error("failed for SysV sempphore (id 0x%x): %s", *sem_ptr, strerror(errno));
		return ZBX_MUTEX_ERROR;
	}

	*sem_ptr = 0;
	
	return ZBX_MUTEX_OK;
}

#else // !HAVE_SQLITE3 || _WINDOWS

int php_sem_get(ZBX_MUTEX* sem_ptr, char* path_name);	{	return ZBX_MUTEX_OK;	}
int php_sem_acquire(ZBX_MUTEX* sem_ptr);				{	return ZBX_MUTEX_OK;	}
int php_sem_release(ZBX_MUTEX* sem_ptr);				{	return ZBX_MUTEX_OK;	}		
int php_sem_remove(ZBX_MUTEX* sem_ptr)					{	return ZBX_MUTEX_OK;	}

#endif // HAVE_SQLITE3 && !_WINDOWS

